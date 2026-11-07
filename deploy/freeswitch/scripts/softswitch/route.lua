--[[
  Softswitch Class 4 call routing. Runs for every call on the carrier profile
  (dialplan/carrier.xml). The decisions are made by PostgreSQL functions
  (softswitch_identify / softswitch_route), the same ones the admin UI's route
  tester uses; this script applies them to the call:

    1. identify the customer by source IP (+ tech prefix)
    2. enforce the customer's channel and CPS limits
    3. ask the routing engine for the vendor attempts (this also reserves funds)
    4. try each vendor gateway in order, failing over on temporary failures
    5. leave softswitch_* variables on the call for the CDR (posted by mod_xml_cdr)

  Installed to $${script_dir}/softswitch/route.lua by install.sh.
]]

local api = freeswitch.API()
local uuid = session:get_uuid()

local function log(level, msg)
  freeswitch.consoleLog(level, "[softswitch] " .. uuid .. " " .. msg .. "\n")
end

local function var(name)
  local v = session:getVariable(name)
  if v == nil or v == "" or v == "_undef_" then return nil end
  return v
end

local function setvar(name, value)
  session:setVariable(name, value ~= nil and tostring(value) or "")
end

-- SQL string literal. Inputs are validated before use; quoting is belt and braces.
local function lit(s)
  if s == nil then return "NULL" end
  return "'" .. tostring(s):gsub("'", "''") .. "'"
end

-- Reject reasons -> SIP response sent to the customer.
local REJECT = {
  unknown_customer     = "403 Forbidden",
  account_inactive     = "403 Forbidden",
  invalid_number       = "484 Address Incomplete",
  blocked_destination  = "403 Forbidden",
  insufficient_funds   = "402 Payment Required",
  no_sell_rate         = "503 Service Unavailable",
  no_routing_plan      = "503 Service Unavailable",
  no_route             = "503 Service Unavailable",
  negative_margin      = "503 Service Unavailable",
  channel_limit        = "503 Service Unavailable",
  cps_limit            = "503 Service Unavailable",
  database_unavailable = "503 Service Unavailable",
}

local function reject(reason)
  local response = REJECT[reason] or "503 Service Unavailable"
  setvar("softswitch_reject_reason", reason)
  setvar("softswitch_sip_code", response:match("^(%d+)"))
  log("NOTICE", "rejected: " .. reason .. " (" .. response .. ")")
  if session:ready() then
    session:execute("respond", response)
  end
end

-- Vendor failures worth trying the next gateway/vendor for. Anything else
-- (busy, not found, no answer, caller hung up...) goes straight back to the customer.
local FAILOVER_SIP = { [408] = true, [480] = true, [488] = true, [500] = true, [502] = true, [503] = true, [504] = true }
local FAILOVER_CAUSE = {
  NORMAL_TEMPORARY_FAILURE = true, RECOVERY_ON_TIMER_EXPIRE = true, DESTINATION_OUT_OF_ORDER = true,
  NETWORK_OUT_OF_ORDER = true, GATEWAY_DOWN = true, PROGRESS_TIMEOUT = true, SWITCH_CONGESTION = true,
  NORMAL_CIRCUIT_CONGESTION = true, INCOMPATIBLE_DESTINATION = true, NO_ROUTE_TRANSIT_NET = true,
  INVALID_GATEWAY = true, NO_USER_RESPONSE = true, SERVICE_UNAVAILABLE = true,
}

-- "2.5" calls per second -> limit app rate syntax ("2/1"; below 1 cps: "1/N").
local function cps_rate(cps)
  cps = tonumber(cps)
  if not cps or cps <= 0 then return nil end
  if cps >= 1 then return math.floor(cps) .. "/1" end
  return "1/" .. math.ceil(1 / cps)
end

-- ---------------------------------------------------------------------------

local src_ip = var("network_addr") or var("sip_network_ip") or ""
local dialled = var("destination_number") or ""

if not src_ip:match("^[%x%.:]+$") or #src_ip > 45 then
  return reject("unknown_customer")
end
if not dialled:match("^[%d%+%*#]+$") or #dialled > 64 then
  return reject("invalid_number")
end

local dsn = freeswitch.getGlobalVariable("softswitch_db_dsn")
local dbh = dsn and freeswitch.Dbh(dsn)
if not dbh or not dbh:connected() then
  log("ERR", "database unavailable")
  return reject("database_unavailable")
end

-- 1. Who is this?
local customer
dbh:query(string.format("SELECT * FROM softswitch_identify(%s::inet, %s)", lit(src_ip), lit(dialled)), function(row)
  customer = row
end)
if not customer then
  dbh:release()
  return reject("unknown_customer")
end
local account_id = tonumber(customer.account_id)
local number = customer.number
setvar("softswitch_account_id", account_id)

-- 2. Customer limits (atomic: the limit app hangs up the call when exceeded).
if tonumber(customer.max_channels) then
  setvar("softswitch_reject_reason", "channel_limit")
  session:execute("limit", "hash softswitch_customer " .. account_id .. " " .. tonumber(customer.max_channels) .. " !NORMAL_TEMPORARY_FAILURE")
  if not session:ready() then dbh:release(); setvar("softswitch_sip_code", 503); return end
end
local rate = cps_rate(customer.max_cps)
if rate then
  setvar("softswitch_reject_reason", "cps_limit")
  session:execute("limit", "hash softswitch_customer_cps " .. account_id .. " " .. rate .. " !NORMAL_TEMPORARY_FAILURE")
  if not session:ready() then dbh:release(); setvar("softswitch_sip_code", 503); return end
end
setvar("softswitch_reject_reason", nil)

-- 3. Route (reserves the call's funds).
local max_call = tonumber(freeswitch.getGlobalVariable("softswitch_max_call_duration")) or 14400
local attempts, first = {}, nil
dbh:query(string.format("SELECT * FROM softswitch_route(%d, %s, %s::uuid, now(), %d)", account_id, lit(number), lit(uuid), max_call), function(row)
  first = first or row
  if row.status == "ok" then
    local n = tonumber(row.attempt)
    attempts[n] = attempts[n] or {
      trunk_id = tonumber(row.trunk_id), dial_number = row.dial_number,
      buy_rate_id = tonumber(row.buy_rate_id), buy_prefix = row.buy_prefix, buy_rate = row.buy_rate,
      max_channels = tonumber(row.trunk_max_channels), gateways = {},
    }
    table.insert(attempts[n].gateways, { id = tonumber(row.gateway_id), name = row.gateway_name })
  end
end)
dbh:release()

if not first then
  return reject("database_unavailable")
end
setvar("softswitch_destination", first.destination)
setvar("softswitch_sell_rate_id", first.sell_rate_id)
if first.status ~= "ok" then
  return reject(first.reason)
end
local max_duration = tonumber(first.max_duration)
setvar("softswitch_max_duration", max_duration)

-- 4. Try the vendors.
session:setVariable("continue_on_fail", "true")
session:setVariable("hangup_after_bridge", "false")
session:setVariable("ignore_early_media", "false")
session:setVariable("call_timeout", freeswitch.getGlobalVariable("softswitch_answer_timeout") or "60")
session:setVariable("progress_timeout", freeswitch.getGlobalVariable("softswitch_progress_timeout") or "10")
-- Offer the vendor exactly the codecs the customer offered (no transcoding).
local offered = var("ep_codec_string")
if offered then session:setVariable("absolute_codec_string", offered) end
-- The customer can't talk longer than they can pay for.
session:setVariable("execute_on_answer", "sched_hangup +" .. max_duration .. " ALLOTTED_TIMEOUT")

local log_rows, last_cause, last_code, answered = {}, "NO_ROUTE_DESTINATION", nil, false

local function record(a, gw, started, cause, code, ok)
  table.insert(log_rows, string.format(
    '{"trunk":%d,"gateway":%d,"buy_rate_id":%d,"buy_prefix":%s,"buy_rate":%s,"start":%d,"end":%d,"sip":%s,"cause":"%s","answered":%s}',
    a.trunk_id, gw.id, a.buy_rate_id or 0, string.format("%q", a.buy_prefix or ""), string.format("%q", a.buy_rate or ""),
    started, os.time(), code and tostring(code) or "null", (cause or ""):gsub("[^%w_]", ""), ok and "true" or "false"))
  setvar("softswitch_attempts", "[" .. table.concat(log_rows, ",") .. "]")
end

for n = 1, #attempts do
  local a = attempts[n]
  if not session:ready() then break end

  -- Trunk channel limit: skip a full trunk (counted per bridged call, released on failover).
  local trunk_ok = true
  if a.max_channels then
    local usage = tonumber(api:execute("limit_usage", "hash softswitch_trunk " .. a.trunk_id)) or 0
    trunk_ok = usage < a.max_channels
  end

  local stop = false
  if trunk_ok then
    session:execute("limit", "hash softswitch_trunk " .. a.trunk_id .. " -1")
    for _, gw in ipairs(a.gateways) do
      if not session:ready() then stop = true; break end
      local started = os.time()
      -- Tag the vendor leg so its own CDR (exact timings, PDD) joins this attempt.
      local leg = "{softswitch_gateway_id=" .. gw.id .. ",softswitch_parent_uuid=" .. uuid .. ",softswitch_attempt_no=" .. (#log_rows + 1) .. "}"
      session:execute("bridge", leg .. "sofia/gateway/" .. gw.name .. "/" .. a.dial_number)

      local disposition = var("originate_disposition") or ""
      local cause = var("last_bridge_hangup_cause") or var("originate_failed_cause") or disposition
      local code = tonumber((var("last_bridge_proto_specific_hangup_cause") or ""):match("sip:(%d+)"))
      answered = disposition == "SUCCESS" or (tonumber(var("answer_epoch")) or 0) > 0
      record(a, gw, started, answered and "ANSWERED" or cause, answered and 200 or code, answered)

      if answered then
        setvar("softswitch_trunk_id", a.trunk_id)
        setvar("softswitch_gateway_id", gw.id)
        setvar("softswitch_buy_rate_id", a.buy_rate_id)
        stop = true
        break
      end
      last_cause = cause ~= "" and cause or "NORMAL_TEMPORARY_FAILURE"
      last_code = code
      if not (FAILOVER_SIP[code] or FAILOVER_CAUSE[last_cause]) then
        stop = true -- final answer from the vendor (busy, not found, no answer...)
        break
      end
      log("INFO", "attempt " .. n .. " via " .. gw.name .. " failed (" .. last_cause .. (code and (", sip " .. code) or "") .. "), failing over")
    end
    if stop then break end
    api:execute("uuid_limit_release", uuid .. " hash softswitch_trunk " .. a.trunk_id)
  else
    last_cause = "NORMAL_CIRCUIT_CONGESTION"
    log("INFO", "trunk " .. a.trunk_id .. " at its channel limit, skipped")
  end
end

-- 5. Tell the customer how it went (an answered call simply ends).
if not answered then
  -- Record what the customer was told (the last vendor's answer, e.g. 486).
  if last_code then setvar("softswitch_sip_code", last_code) end
end
if not answered and session:ready() then
  log("NOTICE", "no vendor completed the call (" .. last_cause .. ")")
  session:hangup(last_cause)
end

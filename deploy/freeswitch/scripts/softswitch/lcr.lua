--[[
  Least-cost routing of one call to the vendors, shared by carrier traffic
  (route.lua) and PBX extensions calling outside numbers (phone.lua).

    lcr.run(session, u, ctx)
      u   = util.new(session)
      ctx = { account_id, number, max_channels, max_cps }

  1. enforce the account's channel and CPS limits
  2. ask the routing engine (softswitch_route) for the vendor attempts; this
     also reserves the call's funds
  3. try each vendor gateway in order, failing over on temporary failures
  4. leave softswitch_* variables on the call for the CDR (mod_xml_cdr)
]]

local util = require "softswitch.util"
local lit = util.lit

local M = {}

-- Vendor failures worth trying the next gateway/vendor for. Anything else
-- (busy, not found, no answer, caller hung up...) goes straight back to the caller.
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

function M.run(session, u, ctx)
  local api = freeswitch.API()
  local uuid = u.uuid
  local var, setvar = u.var, u.setvar
  local account_id = ctx.account_id

  -- 1. Account limits (atomic: the limit app hangs up the call when exceeded).
  if tonumber(ctx.max_channels) then
    setvar("softswitch_reject_reason", "channel_limit")
    session:execute("limit", "hash softswitch_customer " .. account_id .. " " .. tonumber(ctx.max_channels) .. " !NORMAL_TEMPORARY_FAILURE")
    if not session:ready() then setvar("softswitch_sip_code", 503); return end
  end
  local rate = cps_rate(ctx.max_cps)
  if rate then
    setvar("softswitch_reject_reason", "cps_limit")
    session:execute("limit", "hash softswitch_customer_cps " .. account_id .. " " .. rate .. " !NORMAL_TEMPORARY_FAILURE")
    if not session:ready() then setvar("softswitch_sip_code", 503); return end
  end
  setvar("softswitch_reject_reason", nil)

  -- 2. Route (reserves the call's funds).
  local dbh = u.db()
  if not dbh then return u.reject("database_unavailable") end
  local max_call = tonumber(freeswitch.getGlobalVariable("softswitch_max_call_duration")) or 14400
  local attempts, first = {}, nil
  dbh:query(string.format("SELECT * FROM softswitch_route(%d, %s, %s::uuid, now(), %d)", account_id, lit(ctx.number), lit(uuid), max_call), function(row)
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
    return u.reject("database_unavailable")
  end
  setvar("softswitch_destination", first.destination)
  setvar("softswitch_sell_rate_id", first.sell_rate_id)
  if first.status ~= "ok" then
    return u.reject(first.reason)
  end
  local max_duration = tonumber(first.max_duration)
  setvar("softswitch_max_duration", max_duration)

  -- 3. Try the vendors.
  session:setVariable("continue_on_fail", "true")
  session:setVariable("hangup_after_bridge", "false")
  session:setVariable("ignore_early_media", "false")
  session:setVariable("call_timeout", freeswitch.getGlobalVariable("softswitch_answer_timeout") or "60")
  session:setVariable("progress_timeout", freeswitch.getGlobalVariable("softswitch_progress_timeout") or "10")
  -- Offer the vendor exactly the codecs the caller offered (no transcoding).
  -- PBX phones (WebRTC: Opus only) are transcoded instead: carriers rarely take Opus.
  if not ctx.transcode then
    local offered = var("ep_codec_string")
    if offered then session:setVariable("absolute_codec_string", offered) end
  end
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
        u.log("INFO", "attempt " .. n .. " via " .. gw.name .. " failed (" .. last_cause .. (code and (", sip " .. code) or "") .. "), failing over")
      end
      if stop then break end
      api:execute("uuid_limit_release", uuid .. " hash softswitch_trunk " .. a.trunk_id)
    else
      last_cause = "NORMAL_CIRCUIT_CONGESTION"
      u.log("INFO", "trunk " .. a.trunk_id .. " at its channel limit, skipped")
    end
  end

  -- 4. Tell the caller how it went (an answered call simply ends).
  if not answered then
    -- Record what the caller was told (the last vendor's answer, e.g. 486).
    if last_code then setvar("softswitch_sip_code", last_code) end
  end
  if not answered and session:ready() then
    u.log("NOTICE", "no vendor completed the call (" .. last_cause .. ")")
    session:hangup(last_cause)
  end
end

return M

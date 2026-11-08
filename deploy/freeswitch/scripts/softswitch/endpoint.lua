--[[
  Customer PBX/Devices (Routing -> PBX/Devices in the admin UI).

    endpoint.deliver(session, u, account_id, endpoint_id, depth)
        A DID whose destination is a PBX/Device (pbx.lua). Rings its hosts in
        order ("|" failover), or its SIP trunk registration, with its codecs,
        then (devices) applies its call flow:
          always              forward straight away
          no_answer/busy/failed  forward when the hosts give that outcome
          pbx                 a destination on this switch's PBX
        Forwards go out through the customer's LCR and are billed like their
        other outbound calls (direction pbx_out). The caller's number is passed
        on unless the endpoint says to show its own caller ID.

    endpoint.count(session, key, id, max)
        Channel limits: counts this call against hash <key>/<id>; false (not
        counted) when <max> calls are already up.

  The endpoint is read with softswitch_endpoint(id) (SECURITY DEFINER).
]]

local util = require "softswitch.util"

local M = {}

local NO_ANSWER = { NO_ANSWER = true, NO_USER_RESPONSE = true, ALLOTTED_TIMEOUT = true, PROGRESS_TIMEOUT = true, RECOVERY_ON_TIMER_EXPIRE = true }

function M.count(session, key, id, max)
  max = tonumber(max)
  if max then
    local usage = tonumber(freeswitch.API():execute("limit_usage", "hash " .. key .. " " .. id)) or 0
    if usage >= max then return false end
  end
  session:execute("limit", "hash " .. key .. " " .. id .. " -1")
  return true
end

-- "sofia/carrier/<number>@host:port;transport=tcp|..." for each of its hosts,
-- or, for a SIP trunk, the PBX's registration (the DID goes in the To header).
local function dial_string(ep, number)
  local legs = {}
  if (ep.hosts or "") == "" and ep.sip_username and ep.sip_username:match("^[%w%._%-]+$") then
    local domain = freeswitch.getGlobalVariable("domain")
    return "[sip_invite_to_uri=<sip:" .. number .. "@" .. domain .. ">]user/" .. ep.sip_username .. "@" .. domain
  end
  local port = tonumber(ep.port) or 5060
  local transport = (ep.transport == "tcp" or ep.transport == "tls") and (";transport=" .. ep.transport) or ""
  for host in (ep.hosts or ""):gmatch("[^,]+") do
    if host:match("^[%w%.%-:]+$") then
      if host:find(":") then host = "[" .. host .. "]" end -- IPv6
      table.insert(legs, "sofia/carrier/" .. number .. "@" .. host .. ":" .. port .. transport)
    end
  end
  return table.concat(legs, "|")
end

local function forward(session, u, ep)
  if not session:ready() then return end
  local number = ep.forward_number
  if not number or not number:match("^%d+$") then
    u.log("WARNING", "endpoint " .. ep.id .. " has no forward number")
    return session:hangup("UNALLOCATED_NUMBER")
  end
  u.log("INFO", "endpoint " .. ep.id .. ": forwarding to " .. number)
  u.setvar("softswitch_direction", "pbx_out")
  u.setvar("softswitch_forwarded_to", number)
  if ep.forward_caller_id == "endpoint" and ep.caller_id_number then
    session:setVariable("effective_caller_id_number", ep.caller_id_number)
    session:setVariable("effective_caller_id_name", ep.caller_id_number)
  end
  -- Otherwise the original caller's number is passed on unchanged.
  local lcr = require "softswitch.lcr"
  lcr.run(session, u, {
    account_id = tonumber(ep.account_id),
    number = number,
    max_channels = ep.account_max_channels,
    max_cps = ep.account_max_cps,
    transcode = true,
  })
end

-- Ring the hosts. Returns "answered", "busy", "no_answer" or "failed".
local function ring(session, u, ep, number)
  local dial = dial_string(ep, number)
  if dial == "" then return "failed" end
  local seconds = ep.flow == "no_answer" and (tonumber(ep.ring_seconds) or 25)
    or tonumber(freeswitch.getGlobalVariable("softswitch_answer_timeout")) or 60
  local codecs = (ep.codecs or "PCMU,PCMA"):gsub("[^%w%-,]", "")

  session:setVariable("continue_on_fail", "true")
  session:setVariable("hangup_after_bridge", "false")
  session:setVariable("call_timeout", tostring(seconds))
  -- softswitch_pbx_leg: the delivery leg is not a CDR of its own.
  session:execute("bridge", "{softswitch_pbx_leg=true,leg_timeout=" .. seconds .. ",absolute_codec_string='" .. codecs .. "'}" .. dial)

  local disposition = u.var("originate_disposition") or ""
  local cause = u.var("last_bridge_hangup_cause") or u.var("originate_failed_cause") or disposition
  local code = tonumber((u.var("last_bridge_proto_specific_hangup_cause") or ""):match("sip:(%d+)"))
  if disposition == "SUCCESS" or (tonumber(u.var("answer_epoch")) or 0) > 0 then return "answered" end
  if cause == "USER_BUSY" or code == 486 or code == 600 then return "busy" end
  if NO_ANSWER[cause] or code == 408 or code == 480 then return "no_answer" end
  return "failed", cause
end

function M.deliver(session, u, account_id, endpoint_id, depth)
  if not session:ready() then return end
  local ep = endpoint_id and u.row(string.format("SELECT * FROM softswitch_endpoint(%d)", endpoint_id))
  -- PBXs are shared by every customer; a device only takes its own customer's DIDs.
  if not ep or (ep.kind ~= "pbx" and tonumber(ep.account_id) ~= account_id) then
    u.log("WARNING", "PBX/Device " .. tostring(endpoint_id) .. " does not exist")
    return session:hangup("UNALLOCATED_NUMBER")
  end
  u.setvar("softswitch_endpoint_id", ep.id)
  if ep.active ~= "t" then
    u.log("NOTICE", "PBX/Device " .. ep.id .. " is disabled")
    return session:hangup("SUBSCRIBER_ABSENT")
  end
  local number = (u.var("destination_number") or ""):gsub("[^%d]", "")

  if ep.delivery == "flow" and ep.flow == "always" then
    return forward(session, u, ep)
  end
  if ep.delivery == "flow" and ep.flow == "pbx" then
    local pbx = require "softswitch.pbx"
    return pbx.go(session, u, account_id, { type = ep.pbx_type, target = ep.pbx_target }, (depth or 0) + 1)
  end

  -- Inbound and total channel limits: a full PBX/Device is "busy".
  local outcome, cause
  if not M.count(session, "softswitch_endpoint_in", ep.id, ep.max_inbound)
    or not M.count(session, "softswitch_endpoint", ep.id, ep.max_total) then
    u.log("NOTICE", "PBX/Device " .. ep.id .. " at its call limit")
    outcome = "busy"
  else
    outcome, cause = ring(session, u, ep, number)
  end

  if outcome == "answered" then
    if session:ready() then session:hangup("NORMAL_CLEARING") end
    return
  end
  if not session:ready() then return end -- the caller hung up
  u.log("INFO", "PBX/Device " .. ep.id .. ": " .. outcome .. (cause and (" (" .. cause .. ")") or ""))
  if ep.delivery == "flow" and ep.flow == outcome then
    return forward(session, u, ep)
  end
  if outcome == "busy" then
    session:hangup("USER_BUSY")
  elseif outcome == "no_answer" then
    session:hangup("NO_ANSWER")
  else
    session:hangup(cause and cause ~= "" and cause or "NORMAL_TEMPORARY_FAILURE")
  end
end

return M

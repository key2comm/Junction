--[[
  Minimal PBX call handling: ring an extension (voicemail when it doesn't
  answer), run an IVR, leave or check voicemail. Used by phone.lua (calls from
  extensions) and route.lua (DIDs delivered by carriers).

  Numbers are resolved inside one account with softswitch_pbx_dial(); the
  FreeSWITCH database role can only execute these functions.

  A destination is { type = "number" | "voicemail" | "hangup" | "endpoint", target = "1001" }
  ("endpoint": a customer PBX/Device by id, delivered by endpoint.lua).
]]

local util = require "softswitch.util"
local lit = util.lit

local M = {}

local MAX_DEPTH = 5 -- IVR -> IVR -> ... hops before we give up (loops)

local function domain()
  return freeswitch.getGlobalVariable("domain")
end

local function truthy(v)
  return v == "t" or v == "true" or v == true
end

-- "1=number:1001;9=hangup:" -> { ["1"] = {type="number", target="1001"}, ... }
local function parse_options(s)
  local out = {}
  for key, typ, target in (s or ""):gmatch("([%d%*#])=(%a+):([^;]*)") do
    out[key] = { type = typ, target = target ~= "" and target or nil }
  end
  return out
end

local function lookup(u, account_id, number)
  if not number or not number:match("^%d+$") then return nil end
  return u.row(string.format("SELECT * FROM softswitch_pbx_dial(%d, %s)", account_id, lit(number)))
end

function M.voicemail(session, u, sip_username)
  if not session:ready() then return end
  u.setvar("softswitch_pbx_result", "voicemail")
  session:answer()
  session:sleep(250)
  session:execute("voicemail", "default " .. domain() .. " " .. sip_username)
  if session:ready() then session:hangup("NORMAL_CLEARING") end
end

-- An extension's own mailbox (*97). mod_voicemail asks for the PIN.
function M.check_voicemail(session, u, sip_username)
  u.setvar("softswitch_pbx_result", "voicemail_check")
  session:answer()
  session:sleep(250)
  session:execute("voicemail", "check default " .. domain() .. " " .. sip_username)
  if session:ready() then session:hangup("NORMAL_CLEARING") end
end

local function ring(session, u, row)
  local seconds = tonumber(row.ring_seconds) or 25
  session:setVariable("continue_on_fail", "true")
  session:setVariable("hangup_after_bridge", "true")
  session:setVariable("call_timeout", tostring(seconds))
  -- softswitch_pbx_leg: the callee's leg is not a CDR of its own.
  session:execute("bridge", "{softswitch_pbx_leg=true,leg_timeout=" .. seconds .. "}user/" .. row.sip_username .. "@" .. domain())

  if not session:ready() then return end -- answered and finished, or the caller gave up
  local cause = u.var("originate_disposition") or u.var("last_bridge_hangup_cause") or "NO_ANSWER"
  u.log("INFO", "extension " .. row.sip_username .. " did not answer (" .. cause .. ")")
  if truthy(row.voicemail) then
    return M.voicemail(session, u, row.sip_username)
  end
  if cause == "USER_BUSY" then
    session:hangup("USER_BUSY")
  elseif cause == "USER_NOT_REGISTERED" or cause == "SUBSCRIBER_ABSENT" then
    session:hangup("SUBSCRIBER_ABSENT")
  else
    session:hangup("NO_ANSWER")
  end
end

local go

local function ivr(session, u, account_id, row, depth)
  u.setvar("softswitch_pbx_result", "ivr")
  session:answer()
  session:sleep(300)
  local options = parse_options(row.options)
  local greeting = "silence_stream://500"
  if row.greeting and row.greeting:match("^ivr%-%d+%-[%w]+%.wav$") then
    greeting = (freeswitch.getGlobalVariable("softswitch_greeting_dir") or "") .. "/" .. row.greeting
  end
  local timeout = (tonumber(row.timeout_seconds) or 5) * 1000
  local default = row.default_type and { type = row.default_type, target = row.default_target } or nil

  for _ = 1, 3 do
    if not session:ready() then return end
    local digit = session:playAndGetDigits(1, 1, 1, timeout, "", greeting, "", "^[0-9*#]$")
    if digit ~= "" and options[digit] then
      return go(session, u, account_id, options[digit], depth + 1)
    end
    if digit == "" and default then
      return go(session, u, account_id, default, depth + 1)
    end
    if digit ~= "" then
      session:streamFile("ivr/ivr-that_was_an_invalid_entry.wav")
    end
  end
  if session:ready() then session:hangup("NORMAL_CLEARING") end
end

-- Deliver a number inside the account. False when the number doesn't exist.
function M.deliver(session, u, account_id, number, depth)
  depth = depth or 0
  local row = lookup(u, account_id, number)
  if not row then return false end
  if depth > MAX_DEPTH then
    u.log("WARNING", "PBX destination loop at " .. number .. "; hanging up")
    session:hangup("NORMAL_CLEARING")
    return true
  end
  u.setvar("softswitch_pbx_target", number)
  if row.kind == "extension" then
    ring(session, u, row)
  elseif row.kind == "ivr" then
    ivr(session, u, account_id, row, depth)
  end
  return true
end

go = function(session, u, account_id, dest, depth)
  if not session:ready() then return end
  if dest.type == "number" then
    if not M.deliver(session, u, account_id, dest.target, depth) then
      u.log("WARNING", "PBX destination " .. tostring(dest.target) .. " does not exist")
      session:hangup("UNALLOCATED_NUMBER")
    end
  elseif dest.type == "endpoint" then
    if depth > MAX_DEPTH then return session:hangup("NORMAL_CLEARING") end
    local endpoint = require "softswitch.endpoint"
    endpoint.deliver(session, u, account_id, tonumber(dest.target), depth)
  elseif dest.type == "voicemail" then
    local row = lookup(u, account_id, dest.target)
    if row and row.kind == "extension" and truthy(row.voicemail) then
      M.voicemail(session, u, row.sip_username)
    else
      session:hangup("UNALLOCATED_NUMBER")
    end
  else
    session:hangup("NORMAL_CLEARING")
  end
end

M.go = go

-- A DID call from a carrier (route.lua). dest_type/dest_target from softswitch_pbx_did().
function M.inbound(session, u, account_id, dest_type, dest_target)
  go(session, u, account_id, { type = dest_type, target = dest_target }, 0)
end

return M

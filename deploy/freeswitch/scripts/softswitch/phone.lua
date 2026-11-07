--[[
  Calls from PBX phones (internal profile, after digest auth; dialplan/pbx.xml).

    *97            the caller's own voicemail
    1-6 digits     an extension or IVR of the caller's account
    7+ digits      an outside number: least-cost routed and billed to the
                   account exactly like its carrier traffic (lcr.lua)

  The caller is identified by softswitch_pbx_user, which FreeSWITCH sets from
  the directory entry only after successful authentication.
]]

local util = require "softswitch.util"
local pbx = require "softswitch.pbx"
local lcr = require "softswitch.lcr"
local lit = util.lit

local u = util.new(session)
local var, setvar = u.var, u.setvar

local user = var("softswitch_pbx_user")
if not user or not user:match("^[%w%._%-]+$") or #user > 64 then
  return u.reject("unknown_customer")
end

local caller, err = u.row(string.format("SELECT * FROM softswitch_pbx_caller(%s)", lit(user)))
if not caller then
  return u.reject(err or "unknown_customer")
end
local account_id = tonumber(caller.account_id)
setvar("softswitch_account_id", account_id)
setvar("softswitch_direction", "pbx_local")
setvar("softswitch_pbx_extension", caller.extension)
if caller.account_active ~= "t" then
  return u.reject("account_inactive")
end

local dialled = var("destination_number") or ""

if dialled == "*97" then
  return pbx.check_voicemail(session, u, user)
end

if dialled:match("^%d+$") and #dialled <= 6 then
  if not pbx.deliver(session, u, account_id, dialled, 0) then
    u.reject("unknown_extension")
  end
  return
end

local number = dialled:gsub("^%+", "")
if not number:match("^%d+$") or #number < 7 or #number > 32 then
  return u.reject("invalid_number")
end
if caller.outbound ~= "t" then
  return u.reject("outbound_not_allowed")
end

setvar("softswitch_direction", "pbx_out")
if caller.caller_id_number then
  session:setVariable("effective_caller_id_number", caller.caller_id_number)
  session:setVariable("effective_caller_id_name", caller.caller_id_number)
end
lcr.run(session, u, {
  account_id = account_id,
  number = number,
  max_channels = caller.max_channels,
  max_cps = caller.max_cps,
  transcode = true,
})

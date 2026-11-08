--[[
  Calls an outside PBX sends over a device's SIP trunk login (internal profile,
  after digest auth; dialplan/device.xml). They go out through the device's
  customer's least-cost routing and are billed like their other calls, with the
  device's outbound/total limits and caller ID.

  The device is identified by softswitch_device_id, which FreeSWITCH sets from
  the directory entry (PbxSync) only after successful authentication.
]]

local util = require "softswitch.util"
local lcr = require "softswitch.lcr"
local endpoint = require "softswitch.endpoint"
local lit = util.lit

local u = util.new(session)
local var, setvar = u.var, u.setvar

local id = tonumber(var("softswitch_device_id") or "")
if not id then
  return u.reject("unknown_customer")
end

local dev, err = u.row(string.format("SELECT * FROM softswitch_device(%d)", id))
if not dev then
  return u.reject(err or "unknown_customer")
end
setvar("softswitch_account_id", dev.account_id)
setvar("softswitch_endpoint_id", dev.endpoint_id)
setvar("softswitch_direction", "pbx_out")
if dev.active ~= "t" then
  return u.reject("account_inactive")
end

local number = (var("destination_number") or ""):gsub("^%+", "")
number = util.add_one(number, dev.add_one)
if not number:match("^%d+$") or #number < 7 or #number > 32 then
  return u.reject("invalid_number")
end

if not endpoint.count(session, "softswitch_endpoint_out", dev.endpoint_id, dev.max_outbound)
  or not endpoint.count(session, "softswitch_endpoint", dev.endpoint_id, dev.max_total) then
  return u.reject("channel_limit")
end
if dev.caller_id_number then
  session:setVariable("effective_caller_id_number", dev.caller_id_number)
  session:setVariable("effective_caller_id_name", dev.caller_id_number)
end

lcr.run(session, u, {
  account_id = tonumber(dev.account_id),
  number = number,
  max_channels = dev.max_channels,
  max_cps = dev.max_cps,
  transcode = true,
})

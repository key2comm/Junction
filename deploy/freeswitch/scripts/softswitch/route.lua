--[[
  Softswitch Class 4 call routing. Runs for every call on the carrier profile
  (dialplan/carrier.xml). The decisions are made by PostgreSQL functions
  (softswitch_identify / softswitch_route), the same ones the admin UI's route
  tester uses; this script applies them to the call:

    0. a number we host (a PBX DID) goes to its PBX destination instead
    1. identify the customer by source IP (+ tech prefix)
    2. least-cost route it to the vendors (lcr.lua): limits, funds, failover
    3. leave softswitch_* variables on the call for the CDR (posted by mod_xml_cdr)

  Installed to $${script_dir}/softswitch/ by install.sh, with util.lua, lcr.lua
  and pbx.lua.
]]

local util = require "softswitch.util"
local lcr = require "softswitch.lcr"
local lit = util.lit

local u = util.new(session)
local var, setvar = u.var, u.setvar

local src_ip = var("network_addr") or var("sip_network_ip") or ""
local dialled = var("destination_number") or ""

if not src_ip:match("^[%x%.:]+$") or #src_ip > 45 then
  return u.reject("unknown_customer")
end
if not dialled:match("^[%d%+%*#]+$") or #dialled > 64 then
  return u.reject("invalid_number")
end

local dbh = u.db()
if not dbh then
  return u.reject("database_unavailable")
end

-- 0. One of our DIDs? Only allowlisted carriers reach this profile (nftables),
-- and inbound PBX calls are free, so no customer identification is needed.
local did
dbh:query(string.format("SELECT * FROM softswitch_pbx_did(%s)", lit(dialled)), function(row) did = did or row end)
if did then
  dbh:release()
  setvar("softswitch_direction", "pbx_in")
  setvar("softswitch_account_id", did.account_id)
  setvar("softswitch_destination", did.number)
  local pbx = require "softswitch.pbx"
  return pbx.inbound(session, u, tonumber(did.account_id), did.dest_type, did.dest_target)
end

-- 1. Who is this?
local customer
dbh:query(string.format("SELECT * FROM softswitch_identify(%s::inet, %s)", lit(src_ip), lit(dialled)), function(row)
  customer = row
end)
dbh:release()
if not customer then
  return u.reject("unknown_customer")
end
setvar("softswitch_account_id", customer.account_id)

-- 2. Route it.
lcr.run(session, u, {
  account_id = tonumber(customer.account_id),
  number = customer.number,
  max_channels = customer.max_channels,
  max_cps = customer.max_cps,
})

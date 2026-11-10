--[[
  Helpers shared by the Softswitch call scripts (route.lua, phone.lua and the
  lcr / pbx modules). Load with: local util = require "softswitch.util"
]]

local M = {}

function M.new(session)
  local u = {}
  local uuid = session:get_uuid()
  u.uuid = uuid

  function u.log(level, msg)
    freeswitch.consoleLog(level, "[softswitch] " .. uuid .. " " .. msg .. "\n")
  end

  function u.var(name)
    local v = session:getVariable(name)
    if v == nil or v == "" or v == "_undef_" then return nil end
    return v
  end

  function u.setvar(name, value)
    session:setVariable(name, value ~= nil and tostring(value) or "")
  end

  -- The Call-ID for a leg this call places: "<this call's uuid>-<n><random>".
  -- The call's PCAP (Calls -> Call records) finds every leg by that prefix.
  local legs = 0
  function u.leg_call_id()
    legs = legs + 1
    return "sip_invite_call_id=" .. uuid .. "-" .. legs .. string.format("%04x", math.random(0, 0xffff))
  end

  -- Reject reasons -> SIP response sent to the caller.
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
    -- PBX and DIDs
    unknown_extension    = "404 Not Found",
    did_deactivated      = "404 Not Found",
    did_banned           = "403 Forbidden",
    outbound_not_allowed = "403 Forbidden",
  }

  function u.reject(reason)
    local response = REJECT[reason] or "503 Service Unavailable"
    u.setvar("softswitch_reject_reason", reason)
    u.setvar("softswitch_sip_code", response:match("^(%d+)"))
    u.log("NOTICE", "rejected: " .. reason .. " (" .. response .. ")")
    if session:ready() then
      session:execute("respond", response)
    end
  end

  -- A pooled database handle, or nil (logged) when PostgreSQL is unreachable.
  function u.db()
    local dsn = freeswitch.getGlobalVariable("softswitch_db_dsn")
    local dbh = dsn and freeswitch.Dbh(dsn)
    if not dbh or not dbh:connected() then
      u.log("ERR", "database unavailable")
      return nil
    end
    return dbh
  end

  -- First row of a query (or nil). Opens and releases its own handle.
  function u.row(sql)
    local dbh = u.db()
    if not dbh then return nil, "database_unavailable" end
    local found
    dbh:query(sql, function(r) found = found or r end)
    dbh:release()
    return found
  end

  return u
end

-- A device that dials North American numbers as 10 digits (NXX NXX XXXX):
-- add the 1 so they route as 1NXXNXXXXXX. Anything else is left alone.
function M.add_one(number, enabled)
  if enabled == "t" and number:match("^[2-9]%d%d[2-9]%d%d%d%d%d%d$") then
    return "1" .. number
  end
  return number
end

-- SQL string literal. Inputs are validated before use; quoting is belt and braces.
function M.lit(s)
  if s == nil then return "NULL" end
  return "'" .. tostring(s):gsub("'", "''") .. "'"
end

return M

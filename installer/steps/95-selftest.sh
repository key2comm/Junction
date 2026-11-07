# End-to-end self-test: real SIP calls through the switch (fake customer and
# vendor on the loopback-only selftest profile). Checks routing, failover,
# rejections, the funds cut-off and billing, then removes its test data.
# Takes about 40 seconds. Skip with: sudo ./install.sh --steps 10,20,...,90

info "Running the call self-test (about 40 seconds)"
if (cd "$SS_WEB_ROOT" && runuser -u "$SS_APP_USER" -- php artisan softswitch:selftest --no-ansi); then
	info "Self-test passed: the switch routes, fails over and bills calls correctly"
else
	warn "Self-test failed (see above). Re-run it any time with:"
	warn "  sudo -u $SS_APP_USER bash -c 'cd $SS_WEB_ROOT && php artisan softswitch:selftest'"
fi

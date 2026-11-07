# Base packages and repositories (EPEL + CRB).

if ! rpm -q epel-release >/dev/null 2>&1; then
	info "Enabling EPEL"
	pkg_install epel-release 2>/dev/null \
		|| pkg_install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-${SS_EL}.noarch.rpm"
fi

pkg_install dnf-plugins-core
if [ "$SS_OS_ID" = rhel ]; then
	subscription-manager repos --enable "codeready-builder-for-rhel-${SS_EL}-$(arch)-rpms" \
		|| warn "could not enable CodeReady Builder; FreeSWITCH build deps may be missing"
else
	dnf config-manager --set-enabled crb
fi

pkg_install git tar curl ca-certificates chrony
systemctl enable --now chronyd >/dev/null

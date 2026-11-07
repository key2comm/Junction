# Build FreeSWITCH and its SignalWire-maintained deps (sofia-sip, spandsp)
# from public source. No SignalWire token is needed.

pkg_install gcc gcc-c++ make autoconf automake libtool pkgconf-pkg-config \
	diffutils patch which file \
	libtiff-devel libjpeg-turbo-devel sqlite-devel libcurl-devel pcre2-devel \
	speex-devel speexdsp-devel ldns-devel libedit-devel openssl-devel \
	lua-devel libsndfile-devel opus-devel libuuid-devel 'pkgconfig(zlib)' libpq-devel

export PKG_CONFIG_PATH="/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
printf '/usr/local/lib\n/usr/local/lib64\n' > /etc/ld.so.conf.d/softswitch.conf
ldconfig

jobs="$(nproc)"

# Small servers need swap to compile FreeSWITCH.
mem_mb="$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)"
if [ "$mem_mb" -lt 3500 ] && [ -z "$(swapon --noheadings)" ]; then
	info "Low memory (${mem_mb} MB): adding 2 GB swap at /swapfile"
	fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
	chmod 600 /swapfile
	mkswap -q /swapfile
	swapon /swapfile
	grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap defaults 0 0' >> /etc/fstab
fi

install -d "$SS_BUILD_DIR"

# fetch REPO REF DIR - shallow clone/checkout a tag.
fetch() {
	local repo="$1" ref="$2" dir="$SS_BUILD_DIR/$3"
	if [ -d "$dir/.git" ]; then
		git -C "$dir" fetch -q --depth 1 origin tag "$ref"
		git -C "$dir" checkout -q -f "$ref"
	else
		git clone -q --depth 1 --branch "$ref" "https://github.com/$repo.git" "$dir"
	fi
}

# built DIR REF - true if DIR was already built and installed at REF.
built() { [ "$(cat "$SS_BUILD_DIR/$1/.softswitch-built" 2>/dev/null)" = "$2" ]; }
mark_built() { echo "$2" > "$SS_BUILD_DIR/$1/.softswitch-built"; }

# build_lib NAME REPO REF - autotools build into /usr/local.
build_lib() {
	local name="$1" repo="$2" ref="$3"
	if built "$name" "$ref"; then info "$name $ref already installed"; return; fi
	info "Building $name $ref"
	fetch "$repo" "$ref" "$name"
	(
		cd "$SS_BUILD_DIR/$name"
		if [ -x ./bootstrap.sh ]; then ./bootstrap.sh; else ./autogen.sh; fi
		./configure --prefix=/usr/local --disable-static
		make -j"$jobs"
		make install
	) > "$SS_BUILD_DIR/$name.log" 2>&1 || die "$name build failed; see $SS_BUILD_DIR/$name.log"
	ldconfig
	mark_built "$name" "$ref"
}

build_lib spandsp   freeswitch/spandsp   "$SS_SPANDSP_REF"
build_lib sofia-sip freeswitch/sofia-sip "$SS_SOFIA_SIP_REF"

fs_ref="$SS_FREESWITCH_REF+$(sha1sum "$SS_ROOT/deploy/freeswitch/modules.conf" | cut -c1-12)"
if built freeswitch "$fs_ref"; then
	info "FreeSWITCH $SS_FREESWITCH_REF already installed"
else
	info "Building FreeSWITCH $SS_FREESWITCH_REF (this takes a while on small servers; log: $SS_BUILD_DIR/freeswitch.log)"
	fetch signalwire/freeswitch "$SS_FREESWITCH_REF" freeswitch
	(
		cd "$SS_BUILD_DIR/freeswitch"
		./bootstrap.sh -j
		cp "$SS_ROOT/deploy/freeswitch/modules.conf" modules.conf
		./configure --prefix="$SS_FS_PREFIX" --disable-libvpx --disable-libyuv
		make -j"$jobs"
		make install
		make sounds-install moh-install || echo "WARN: sound download failed"
	) > "$SS_BUILD_DIR/freeswitch.log" 2>&1 || die "FreeSWITCH build failed; see $SS_BUILD_DIR/freeswitch.log"
	mark_built freeswitch "$fs_ref"
fi

getent group freeswitch >/dev/null || groupadd --system freeswitch
getent passwd freeswitch >/dev/null || useradd --system -g freeswitch -d "$SS_FS_PREFIX" -s /sbin/nologin freeswitch
chown -R freeswitch:freeswitch "$SS_FS_PREFIX"
chmod -R o-rwx "$SS_FS_PREFIX"

ln -sf "$SS_FS_PREFIX/bin/fs_cli" /usr/local/bin/fs_cli
ln -sf "$SS_FS_PREFIX/bin/freeswitch" /usr/local/bin/freeswitch

render "$SS_ROOT/deploy/systemd/freeswitch.service" /etc/systemd/system/freeswitch.service
systemctl daemon-reload
systemctl enable -q freeswitch
info "FreeSWITCH installed at $SS_FS_PREFIX"

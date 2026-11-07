<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import { Mic, MicOff, Phone, PhoneIncoming, PhoneOff } from '@lucide/vue';
import * as JsSIP from 'jssip';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';
import { phone as adminPhone, show as showPbx } from '@/routes/accounts/pbx';
import { index as pbxIndex } from '@/routes/pbx';
import { phone as portalPhone } from '@/routes/portal';

type Credentials = { id: number; extension: string; name: string | null; username: string; password: string };

const props = defineProps<{
    account: { id: number; name: string; code: string };
    admin: boolean;
    extensions: { id: number; extension: string; name: string | null }[];
    credentials: Credentials | null;
    connection: { host: string; wss: string };
}>();

defineOptions({
    layout: (props: { admin: boolean; account: { id: number; name: string } }) => ({
        breadcrumbs: props.admin
            ? [
                  { title: 'PBX', href: pbxIndex() },
                  { title: props.account.name, href: showPbx(props.account.id) },
                  { title: 'Web phone', href: adminPhone(props.account.id) },
              ]
            : [{ title: 'Web phone', href: portalPhone() }],
    }),
});

// --- JsSIP ------------------------------------------------------------------
// Minimal shapes of the JsSIP objects used here (its own typings are loose).
type Session = {
    direction: 'incoming' | 'outgoing';
    remote_identity: { uri: { user: string }; display_name?: string };
    connection?: RTCPeerConnection;
    answer: (options?: object) => void;
    terminate: () => void;
    mute: () => void;
    unmute: () => void;
    sendDTMF: (tone: string) => void;
    on: (event: string, cb: (e: any) => void) => void; // eslint-disable-line @typescript-eslint/no-explicit-any
};

const status = ref<'offline' | 'connecting' | 'registered' | 'failed'>('offline');
const statusText = ref('Not connected');
const ua = shallowRef<JsSIP.UA | null>(null);
const session = shallowRef<Session | null>(null);
const callState = ref<'idle' | 'ringing-in' | 'calling' | 'in-call'>('idle');
const peer = ref('');
const muted = ref(false);
const number = ref('');
const remoteAudio = ref<HTMLAudioElement | null>(null);
const startedAt = ref<number | null>(null);
const now = ref(Date.now());
const ticker = setInterval(() => (now.value = Date.now()), 1000);

const duration = computed(() => {
    if (!startedAt.value) return '';
    const s = Math.floor((now.value - startedAt.value) / 1000);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
});

function connect() {
    const c = props.credentials;
    if (!c) return;
    disconnect();
    status.value = 'connecting';
    statusText.value = 'Connecting…';
    const socket = new JsSIP.WebSocketInterface(props.connection.wss);
    const agent = new JsSIP.UA({
        sockets: [socket],
        uri: `sip:${c.username}@${props.connection.host}`,
        password: c.password,
        authorization_user: c.username,
        display_name: c.name ?? c.extension,
        register: true,
        session_timers: false,
        user_agent: 'Softswitch web phone',
    });
    // JsSIP's event typings are stricter than useful here.
    const on = (event: string, cb: (e: any) => void) => (agent as unknown as { on: (ev: string, cb: (e: any) => void) => void }).on(event, cb); // eslint-disable-line @typescript-eslint/no-explicit-any
    on('registered', () => {
        status.value = 'registered';
        statusText.value = `Ready as extension ${c.extension}`;
    });
    on('unregistered', () => {
        if (status.value !== 'failed') {
            status.value = 'offline';
            statusText.value = 'Not registered';
        }
    });
    on('registrationFailed', (e: { cause?: string }) => {
        status.value = 'failed';
        statusText.value = `Registration failed: ${e.cause ?? 'unknown'}`;
    });
    on('disconnected', () => {
        if (status.value === 'registered' || status.value === 'connecting') {
            status.value = 'failed';
            statusText.value = 'Lost the connection to the switch; retrying…';
        }
    });
    on('newRTCSession', (e: { session: Session; originator: string }) => {
        if (session.value) {
            if (e.originator === 'remote') e.session.terminate(); // one call at a time
            return;
        }
        attach(e.session);
        if (e.originator === 'remote') {
            callState.value = 'ringing-in';
            peer.value = e.session.remote_identity.display_name || e.session.remote_identity.uri.user;
        }
    });
    agent.start();
    ua.value = agent;
}

function disconnect() {
    session.value?.terminate();
    ua.value?.stop();
    ua.value = null;
    status.value = 'offline';
    statusText.value = 'Not connected';
}

function attach(s: Session) {
    session.value = s;
    muted.value = false;
    const hookAudio = () => {
        s.connection?.addEventListener('track', (ev: RTCTrackEvent) => {
            if (remoteAudio.value && ev.streams[0]) remoteAudio.value.srcObject = ev.streams[0];
        });
    };
    if (s.connection) hookAudio();
    else s.on('peerconnection', hookAudio);
    s.on('progress', () => {
        if (s.direction === 'outgoing') statusText.value = 'Ringing…';
    });
    s.on('confirmed', () => {
        callState.value = 'in-call';
        startedAt.value = Date.now();
    });
    const end = (e: { cause?: string }) => {
        session.value = null;
        callState.value = 'idle';
        startedAt.value = null;
        if (status.value === 'registered') statusText.value = e?.cause && e.cause !== 'Terminated' ? `Call ended: ${e.cause}` : `Ready as extension ${props.credentials?.extension}`;
    };
    s.on('ended', end);
    s.on('failed', end);
}

const mediaOptions = { mediaConstraints: { audio: true, video: false }, pcConfig: { iceServers: [] } };

function call() {
    const target = number.value.replace(/[^\d*#+]/g, '');
    if (!ua.value || !target) return;
    peer.value = target;
    callState.value = 'calling';
    statusText.value = `Calling ${target}…`;
    (ua.value as unknown as { call: (target: string, options: object) => void }).call(`sip:${target}@${props.connection.host}`, mediaOptions);
}

const answer = () => session.value?.answer(mediaOptions);
const hangup = () => session.value?.terminate();
function toggleMute() {
    if (!session.value) return;
    muted.value ? session.value.unmute() : session.value.mute();
    muted.value = !muted.value;
}
function key(k: string) {
    if (callState.value === 'in-call') session.value?.sendDTMF(k);
    else number.value += k;
}

function choose(e: Event) {
    const id = (e.target as HTMLSelectElement).value;
    disconnect();
    router.get(props.admin ? adminPhone.url(props.account.id) : portalPhone.url(), { extension: id }, { preserveScroll: true });
}

onBeforeUnmount(() => {
    clearInterval(ticker);
    disconnect();
});

const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];
</script>

<template>
    <Head title="Web phone" />

    <div class="ss-maxw-md ss-stack-4">
        <Heading title="Web phone" :description="`Make and take calls in the browser as one of ${account.name}'s extensions.`" />

        <p v-if="!credentials" class="card card-body text-secondary">There are no enabled extensions yet. An administrator adds them on the PBX page.</p>

        <template v-else>
            <div class="ss-grid gap-1">
                <Label for="ext">Extension</Label>
                <select id="ext" :class="selectClass" :value="credentials.id" @change="choose">
                    <option v-for="e in extensions" :key="e.id" :value="e.id">{{ e.extension }} {{ e.name }}</option>
                </select>
            </div>

            <div class="card card-body d-flex align-items-center justify-content-between gap-2 flex-row">
                <div class="d-flex align-items-center gap-2">
                    <Badge :variant="status === 'registered' ? 'default' : status === 'failed' ? 'destructive' : 'secondary'">{{ status }}</Badge>
                    <span>{{ statusText }}</span>
                </div>
                <Button v-if="status === 'offline' || status === 'failed'" size="sm" @click="connect">Connect</Button>
                <Button v-else size="sm" variant="outline" @click="disconnect">Disconnect</Button>
            </div>

            <div v-if="callState === 'ringing-in'" class="d-flex align-items-center justify-content-between gap-2 rounded border border-primary p-2">
                <span class="d-flex align-items-center gap-2 fw-medium"><PhoneIncoming class="icon" /> {{ peer }} is calling</span>
                <div class="d-flex gap-2">
                    <Button size="sm" @click="answer"><Phone class="icon" /> Answer</Button>
                    <Button size="sm" variant="destructive" @click="hangup"><PhoneOff class="icon" /> Decline</Button>
                </div>
            </div>

            <div v-if="callState === 'calling' || callState === 'in-call'" class="d-flex align-items-center justify-content-between gap-2 rounded border p-2">
                <span class="fw-medium">{{ peer }} <span v-if="duration" class="ms-2 font-monospace text-secondary tabular-nums">{{ duration }}</span></span>
                <div class="d-flex gap-2">
                    <Button size="sm" variant="outline" :disabled="callState !== 'in-call'" :aria-label="muted ? 'Unmute' : 'Mute'" @click="toggleMute">
                        <MicOff v-if="muted" class="icon" /><Mic v-else class="icon" />
                    </Button>
                    <Button size="sm" variant="destructive" @click="hangup"><PhoneOff class="icon" /> Hang up</Button>
                </div>
            </div>

            <form class="ss-stack-2" @submit.prevent="call">
                <Input v-model="number" inputmode="tel" placeholder="Extension or number" aria-label="Number to call" class="text-center font-monospace fs-3" />
                <div class="ss-grid ss-cols-3 gap-2">
                    <Button v-for="k in keys" :key="k" type="button" variant="outline" class="py-2 font-monospace fs-3" @click="key(k)">{{ k }}</Button>
                </div>
                <Button type="submit" class="w-100" :disabled="status !== 'registered' || callState !== 'idle' || !number"><Phone class="icon" /> Call</Button>
            </form>

            <p class="small text-secondary">
                2-6 digits call an extension or IVR, longer numbers go out to carriers (international format), <span class="font-monospace">*97</span> is voicemail. Not for emergency calls. The browser asks for the microphone on the first call.
            </p>
        </template>

        <audio ref="remoteAudio" autoplay></audio>
    </div>
</template>

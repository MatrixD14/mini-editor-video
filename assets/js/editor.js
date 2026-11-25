(() => {
'use strict';

const MIN_CLIP_DURATION = 0.1;
const MIN_OVERLAY_DURATION = 0.2;
const PPS_LEVELS = [0.5, 1, 2, 5, 10, 20, 50, 100, 200, 400];
const FRAME_MODE_PPS = 100;
const PAD_LEFT  = 60;
const PAD_RIGHT = 60;
const HISTORY_MAX = 80;

const $ = s => document.querySelector(s);
const video           = $('#preview');
const videoContainer  = $('#video-container');
const overlayLayer    = $('#overlay-layer');
const timelineScroll  = $('#timeline-scroll');
const timelineContent = $('#timeline-content');
const ruler           = $('#ruler');
const trackText       = $('#overlay-track-text');
const trackImage      = $('#overlay-track-image');
const playheadEl      = $('#playhead');
const playheadHandle  = playheadEl.querySelector('.playhead-handle');
const bufferingEl     = $('#buffering');
const zoomLabel       = $('#zoom-label');
const textModal       = $('#text-modal');
const cropModal     = $('#crop-modal');
const cropStage     = $('#crop-stage');
const cropImg       = $('#crop-img');
const cropSel       = $('#crop-selection');

let PPS = 10;
let zoomIndex = 3;
let suppressAutoScroll = false;
let scrollRaf = null;
let editingOverlayId = null;
let isNewOverlay = false;

const state = {
  source: null,
  clips: [],
  overlays: [],
  currentClipIndex: 0,
  selectedOverlayId: null,
  playing: false,
  muted: false,
  vt: 0,
};

const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

function getFps() { return (state.source && state.source.fps) || 30; }

function formatTime(sec) {
  if (!isFinite(sec) || sec < 0) sec = 0;
  const fps = Math.round(getFps()) || 30;
  const tf = Math.round(sec * fps);
  const f = tf % fps;
  const ts = Math.floor(tf / fps);
  const s = ts % 60, m = Math.floor(ts / 60) % 60, h = Math.floor(ts / 3600);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}:${String(f).padStart(2,'0')}`;
}
function formatShort(sec) {
  if (sec === 0) return '0';
  if (sec < 60) return sec % 1 === 0 ? `${sec}s` : `${sec.toFixed(1)}s`;
  if (sec < 3600) {
    const m = Math.floor(sec / 60), s = Math.floor(sec % 60);
    return s === 0 ? `${m}m` : `${m}:${String(s).padStart(2,'0')}`;
  }
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
  return `${h}:${String(m).padStart(2,'0')}`;
}
function totalDuration() {
  return state.clips.reduce((a, c) => a + (c.sourceEnd - c.sourceStart), 0);
}
function getRulerStep(pps) {
  const targetSec = 110 / pps;
  const nice = [0.1, 0.2, 0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 1800];
  for (const v of nice) if (v >= targetSec) return v;
  return nice[nice.length - 1];
}
function minPPSFor(duration) {
  const vw = (timelineScroll.clientWidth || 400) - PAD_LEFT - PAD_RIGHT;
  return Math.max(0.1, vw / Math.max(duration, 1));
}

function updateTimeDisplay(vt) {
  $('#time-current').textContent = formatTime(vt);
  $('#time-total').textContent   = formatTime(totalDuration());
}

/* ---------- Undo/Redo ---------- */
const history = { stack: [], index: -1 };
function snapshot() {
  history.stack = history.stack.slice(0, history.index + 1);
  const s = {
    clips: state.clips.map(c => ({ sourceStart: c.sourceStart, sourceEnd: c.sourceEnd })),
    overlays: state.overlays.map(o => ({ ...o })),
    muted: state.muted,
  };
  history.stack.push(JSON.stringify(s));
  if (history.stack.length > HISTORY_MAX) history.stack.shift();
  history.index = history.stack.length - 1;
  updateUndoRedo();
}
function updateUndoRedo() {
  $('#btn-undo').disabled = history.index <= 0;
  $('#btn-redo').disabled = history.index >= history.stack.length - 1;
}
function restoreHistory(snap) {
  state.clips = snap.clips.map(c => ({ ...c }));
  state.overlays = snap.overlays.map(o => ({ ...o }));
  state.muted = snap.muted;
  video.muted = state.muted;
  $('#btn-mute').textContent = state.muted ? '🔇' : '🔊';
  $('#btn-mute').classList.toggle('active', state.muted);

  if (state.currentClipIndex >= state.clips.length) state.currentClipIndex = Math.max(0, state.clips.length - 1);
  state.vt = 0;
  if (state.clips[0]) video.currentTime = state.clips[0].sourceStart;

  relayout(); renderRuler(); renderOverlayPills(); renderOverlayElements();
  positionOverlays(); updatePlayhead(); updateAllButtons();
}
function doUndo() { if (history.index > 0) { history.index--; restoreHistory(JSON.parse(history.stack[history.index])); updateUndoRedo(); } }
function doRedo() { if (history.index < history.stack.length - 1) { history.index++; restoreHistory(JSON.parse(history.stack[history.index])); updateUndoRedo(); } }
$('#btn-undo').onclick = doUndo;
$('#btn-redo').onclick = doRedo;

/* ---------- Buffering ---------- */
function showBuffering(on) { if (bufferingEl) bufferingEl.style.display = on ? 'block' : 'none'; }

/* ---------- Upload vídeo ---------- */
$('#file').onchange = async (e) => {
  const f = e.target.files[0];
  if (!f) return;
  $('#info').textContent = 'Enviando...';
  const fd = new FormData(); fd.append('video', f);
  const res = await fetch('api/upload.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.error) { $('#info').textContent = 'Erro: ' + data.error; return; }

  state.source = data;
  state.clips = [];
  state.overlays = [];
  state.selectedOverlayId = null;
  state.currentClipIndex = 0;
  state.vt = 0;
  history.stack = []; history.index = -1;

  $('#info').textContent = `Gerando preview (${data.width}×${data.height}, ${formatTime(data.duration)})...`;
  $('#upload-area').style.display = 'none';
  await waitForProxy(data.proxy_job);
};

async function waitForProxy(jobId) {
  while (true) {
    const res = await fetch('api/status.php?id=' + jobId);
    const j = await res.json();
    if (j.status === 'done')  { startEditing(j.proxy_url); return; }
    if (j.status === 'error') { $('#info').textContent = 'Erro no preview: ' + j.error; return; }
    await new Promise(r => setTimeout(r, 800));
  }
}

function startEditing(proxyUrl) {
  video.src = proxyUrl;
  video.muted = state.muted;
  video.addEventListener('loadedmetadata', () => {
    const dur = isFinite(video.duration) ? video.duration : state.source.duration;
    state.source.duration = dur;
    const basePPS = Math.max(minPPSFor(dur), Math.min(50, 6000 / Math.max(dur, 1)));
    zoomIndex = findClosestLevel(basePPS);
    PPS = PPS_LEVELS[zoomIndex];
    updateZoomLabel();

    $('#info').textContent =
      `${state.source.width}×${state.source.height} · ${formatTime(dur)} · ${getFps().toFixed(0)} fps · ` +
      (state.source.has_audio ? 'com áudio' : 'sem áudio');

    state.clips = [{ sourceStart: 0, sourceEnd: dur }];
    state.currentClipIndex = 0;
    state.vt = 0;

    $('#preview-wrap').classList.add('active');
    requestAnimationFrame(() => {
      relayout(); renderRuler(); renderOverlayPills(); renderOverlayElements();
      positionOverlays(); updatePlayhead(); updateAllButtons();
      snapshot();
    });
  }, { once: true });
}

/* ---------- Zoom ---------- */
function findClosestLevel(t) {
  let b = 0, bd = Infinity;
  PPS_LEVELS.forEach((p, i) => { const d = Math.abs(p - t); if (d < bd) { bd = d; b = i; } });
  return b;
}
function updateZoomLabel() { zoomLabel.textContent = PPS >= 1 ? `${PPS} px/s` : `${PPS.toFixed(1)} px/s`; }
function setPPS(newPPS) {
  if (!state.clips.length) return;
  newPPS = clamp(newPPS, minPPSFor(totalDuration()), PPS_LEVELS[PPS_LEVELS.length - 1]);
  if (newPPS === PPS) return;
  const oldPPS = PPS;
  const vCenter = (timelineScroll.scrollLeft + timelineScroll.clientWidth / 2 - PAD_LEFT) / oldPPS;
  PPS = newPPS;
  relayout(); renderRuler(); renderOverlayPills();
  timelineScroll.scrollLeft = Math.max(0, PAD_LEFT + vCenter * PPS - timelineScroll.clientWidth / 2);
  updatePlayhead();
  updateZoomLabel();
  positionOverlays();
}
function zoomIn()  { if (zoomIndex < PPS_LEVELS.length - 1) { zoomIndex++; setPPS(PPS_LEVELS[zoomIndex]); } }
function zoomOut() { if (zoomIndex > 0)                     { zoomIndex--; setPPS(PPS_LEVELS[zoomIndex]); } }
$('#btn-zoom-in').onclick = zoomIn;
$('#btn-zoom-out').onclick = zoomOut;

/* ---------- Layout ---------- */
function relayout() {
  const w = Math.max(PAD_LEFT + totalDuration() * PPS + PAD_RIGHT, timelineScroll.clientWidth);
  timelineContent.style.width = w + 'px';
  ruler.style.width = w + 'px';
  trackText.style.width = w + 'px';
  trackImage.style.width = w + 'px';
}

/* ---------- Régua ---------- */
function renderRuler() {
  ruler.innerHTML = '';
  if (!state.clips.length) return;
  const total = totalDuration();
  const step = getRulerStep(PPS);
  const showFr = PPS >= FRAME_MODE_PPS;
  const showSub = PPS >= 400;
  const fps = getFps();

  const pad = 120;
  const vStart = Math.max(0, (timelineScroll.scrollLeft - PAD_LEFT - pad) / PPS);
  const vEnd   = Math.min(total, (timelineScroll.scrollLeft + timelineScroll.clientWidth - PAD_LEFT + pad) / PPS);
  const firstTick = Math.ceil(vStart / step) * step;

  for (let t = firstTick; t <= vEnd + 0.0001; t += step) {
    const px = PAD_LEFT + t * PPS;
    const tick = document.createElement('div');
    tick.className = 'ruler-tick major';
    tick.style.left = px + 'px';
    ruler.appendChild(tick);
    const lbl = document.createElement('div');
    lbl.className = 'ruler-label';
    lbl.style.left = px + 'px';
    lbl.textContent = showFr ? formatTime(t) : formatShort(t);
    ruler.appendChild(lbl);
  }

  if (showSub) {
    const fStep = 1 / fps;
    for (let t = Math.max(0, vStart); t <= Math.min(total, vEnd); t += fStep) {
      const mod = t % step;
      if (mod < fStep / 2 || step - mod < fStep / 2) continue;
      const tick = document.createElement('div');
      tick.className = 'ruler-tick frame';
      tick.style.left = (PAD_LEFT + t * PPS) + 'px';
      ruler.appendChild(tick);
    }
  }

  let cum = 0;
  for (let i = 0; i < state.clips.length - 1; i++) {
    cum += state.clips[i].sourceEnd - state.clips[i].sourceStart;
    const m = document.createElement('div');
    m.className = 'cut-marker';
    m.style.left = (PAD_LEFT + cum * PPS) + 'px';
    ruler.appendChild(m);
  }
}

timelineScroll.addEventListener('scroll', () => {
  if (!state.clips.length) return;
  if (scrollRaf) return;
  scrollRaf = requestAnimationFrame(() => {
    scrollRaf = null;
    renderRuler();
    positionOverlays();
  });
}, { passive: true });

/* ---------- Tempo virtual ---------- */
function getVirtualTime() { return state.vt; }
function syncVtFromVideo() {
  let t = 0;
  for (let i = 0; i < state.currentClipIndex; i++) {
    const c = state.clips[i]; if (c) t += c.sourceEnd - c.sourceStart;
  }
  const c = state.clips[state.currentClipIndex];
  if (c) {
    const st = clamp(video.currentTime, c.sourceStart, c.sourceEnd);
    t += Math.max(0, st - c.sourceStart);
  }
  state.vt = t;
}
function updatePlayhead() {
  const vt = getVirtualTime();
  const px = PAD_LEFT + vt * PPS;
  playheadEl.style.left = px + 'px';
  if (!suppressAutoScroll) {
    const s = timelineScroll, pad = 40;
    if (px < s.scrollLeft) s.scrollLeft = Math.max(0, px - pad);
    else if (px > s.scrollLeft + s.clientWidth - pad) s.scrollLeft = px - s.clientWidth + pad;
  }
  updateTimeDisplay(vt);
  updateOverlayVisibility();
}

/* ---------- Seek ---------- */
let seekTargetVt = null, seekRaf = null, pendingSeekTarget = null, seekRetries = 0;
const MAX_SEEK_RETRIES = 5;
function isSeekable(t) {
  try { for (let j = 0; j < video.seekable.length; j++) if (t >= video.seekable.start(j) && t <= video.seekable.end(j)) return true; } catch (_) {}
  return false;
}
function applySeek(vt) {
  vt = clamp(vt, 0, totalDuration());
  if (!state.clips.length) return;
  if (PPS >= FRAME_MODE_PPS) { const fps = getFps(); vt = Math.round(vt * fps) / fps; vt = clamp(vt, 0, totalDuration()); }
  let cum = 0;
  for (let i = 0; i < state.clips.length; i++) {
    const c = state.clips[i], d = c.sourceEnd - c.sourceStart;
    if (vt <= cum + d || i === state.clips.length - 1) {
      state.currentClipIndex = i;
      const target = c.sourceStart + clamp(vt - cum, 0, d);
      state.vt = vt;
      playheadEl.style.left = (PAD_LEFT + vt * PPS) + 'px';
      updateTimeDisplay(vt);
      updateOverlayVisibility();
      updateAllButtons();
      if (Math.abs(video.currentTime - target) < 0.03) return;
      pendingSeekTarget = target; seekRetries = 0;
      if (!isSeekable(target)) showBuffering(true);
      try { video.currentTime = target; } catch (_) { showBuffering(false); pendingSeekTarget = null; }
      return;
    }
    cum += d;
  }
}
function smoothSeek(vt) {
  seekTargetVt = vt;
  if (seekRaf) return;
  seekRaf = requestAnimationFrame(() => { seekRaf = null; if (seekTargetVt != null) applySeek(seekTargetVt); });
}

/* ---------- Playback ---------- */
function setPlaying(play) {
  if (play) {
    if (state.currentClipIndex >= state.clips.length) {
      state.currentClipIndex = 0; video.currentTime = state.clips[0].sourceStart;
    } else {
      const c = state.clips[state.currentClipIndex];
      if (video.currentTime < c.sourceStart || video.currentTime >= c.sourceEnd) video.currentTime = c.sourceStart;
    }
    state.playing = true;
    $('#btn-play').textContent = '⏸';
    video.play().catch(() => { state.playing = false; $('#btn-play').textContent = '▶'; });
    requestAnimationFrame(tick);
  } else video.pause();
}
function tick() {
  if (!state.playing) return;
  const c = state.clips[state.currentClipIndex];
  if (!c) { state.playing = false; return; }
  syncVtFromVideo();
  if (video.currentTime >= c.sourceEnd - 0.03) {
    if (state.currentClipIndex < state.clips.length - 1) {
      state.currentClipIndex++;
      video.currentTime = state.clips[state.currentClipIndex].sourceStart;
    } else {
      video.pause(); video.currentTime = c.sourceEnd;
      state.playing = false; $('#btn-play').textContent = '▶';
      updatePlayhead(); return;
    }
  }
  updatePlayhead();
  requestAnimationFrame(tick);
}
$('#btn-play').onclick = () => { if (state.clips.length) setPlaying(!state.playing); };

video.addEventListener('play',  () => { if (!state.playing) { state.playing = true; $('#btn-play').textContent = '⏸'; requestAnimationFrame(tick); } });
video.addEventListener('pause', () => { if (state.playing) { state.playing = false; $('#btn-play').textContent = '▶'; updatePlayhead(); } });
video.addEventListener('seeking', () => {
  const c = state.clips[state.currentClipIndex]; if (!c) return;
  const t = video.currentTime;
  if (t < c.sourceStart - 0.5 || t > c.sourceEnd + 0.5) {
    const idx = state.clips.findIndex(cl => t >= cl.sourceStart && t <= cl.sourceEnd);
    if (idx >= 0) state.currentClipIndex = idx;
  }
});
video.addEventListener('seeked', () => { pendingSeekTarget = null; seekRetries = 0; showBuffering(false); });
video.addEventListener('waiting', () => showBuffering(true));
video.addEventListener('stalled', () => showBuffering(true));
video.addEventListener('playing', () => showBuffering(false));
video.addEventListener('canplay', () => showBuffering(false));
video.addEventListener('progress', () => {
  if (pendingSeekTarget === null) return;
  if (seekRetries >= MAX_SEEK_RETRIES) { pendingSeekTarget = null; showBuffering(false); return; }
  if (Math.abs(video.currentTime - pendingSeekTarget) > 0.5) { seekRetries++; try { video.currentTime = pendingSeekTarget; } catch (_) {} }
  else pendingSeekTarget = null;
});
window.addEventListener('resize', () => { positionOverlays(); });

/* ---------- Régua: tap = seek ---------- */
ruler.addEventListener('pointerdown', (e) => {
  if (e.button !== 0) return;
  const rect = ruler.getBoundingClientRect();
  const startX = e.clientX;
  const seekVt = (startX - rect.left - PAD_LEFT) / PPS;
  let moved = false, cancelled = false;
  const onMove = (ev) => { if (Math.abs(ev.clientX - startX) > 8) moved = true; };
  const onUp = () => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onCancel);
    if (!moved && !cancelled) smoothSeek(seekVt);
  };
  const onCancel = () => { cancelled = true; document.removeEventListener('pointermove', onMove); document.removeEventListener('pointerup', onUp); document.removeEventListener('pointercancel', onCancel); };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onCancel);
});

/* ---------- Playhead handle ---------- */
playheadHandle.addEventListener('pointerdown', (e) => {
  e.preventDefault(); e.stopPropagation();
  playheadHandle.setPointerCapture(e.pointerId);
  suppressAutoScroll = true;
  const rect = timelineContent.getBoundingClientRect();
  const fromX = x => clamp((x - rect.left - PAD_LEFT) / PPS, 0, totalDuration());
  smoothSeek(fromX(e.clientX));
  const onMove = ev => smoothSeek(fromX(ev.clientX));
  const onUp = () => {
    suppressAutoScroll = false;
    playheadHandle.removeEventListener('pointermove', onMove);
    playheadHandle.removeEventListener('pointerup', onUp);
    playheadHandle.removeEventListener('pointercancel', onUp);
    if (seekTargetVt != null) applySeek(seekTargetVt);
  };
  playheadHandle.addEventListener('pointermove', onMove);
  playheadHandle.addEventListener('pointerup', onUp);
  playheadHandle.addEventListener('pointercancel', onUp);
});

/* ---------- Tap / double-tap ---------- */
const tapState = { id: null, time: 0 };
function registerTap(id, onSingle, onDouble) {
  const now = Date.now();
  if (tapState.id === id && now - tapState.time < 400) {
    onDouble && onDouble();
    tapState.id = null;
    tapState.time = 0;
  } else {
    tapState.id = id;
    tapState.time = now;
    onSingle && onSingle();
  }
}

/* ---------- Botões de clipe ---------- */
function updateAllButtons() {
  const idx = state.currentClipIndex;
  $('#btn-delete').disabled     = state.clips.length <= 1;
  $('#btn-move-left').disabled  = state.clips.length <= 1 || idx <= 0;
  $('#btn-move-right').disabled = state.clips.length <= 1 || idx >= state.clips.length - 1;
}
function doSplit() {
  if (!state.clips.length) return;
  const vt = getVirtualTime();
  let cum = 0;
  for (let i = 0; i < state.clips.length; i++) {
    const c = state.clips[i], d = c.sourceEnd - c.sourceStart;
    if (vt <= cum + d) {
      const off = vt - cum;
      if (off < MIN_CLIP_DURATION || off > d - MIN_CLIP_DURATION) return;
      const split = c.sourceStart + off;
      const nc = { sourceStart: split, sourceEnd: c.sourceEnd };
      c.sourceEnd = split;
      state.clips.splice(i + 1, 0, nc);
      state.currentClipIndex = i;
      relayout(); renderRuler(); updatePlayhead(); updateAllButtons(); snapshot();
      return;
    }
    cum += d;
  }
}
$('#btn-split').onclick = doSplit;
function doDelete() {
  if (state.clips.length <= 1) return;
  state.clips.splice(state.currentClipIndex, 1);
  if (state.currentClipIndex >= state.clips.length) state.currentClipIndex = state.clips.length - 1;
  state.vt = 0; if (state.clips[0]) video.currentTime = state.clips[0].sourceStart;
  relayout(); renderRuler(); updatePlayhead(); updateAllButtons(); snapshot();
}
$('#btn-delete').onclick = doDelete;
function moveCurrent(delta) {
  const i = state.currentClipIndex, j = i + delta;
  if (j < 0 || j >= state.clips.length) return;
  [state.clips[i], state.clips[j]] = [state.clips[j], state.clips[i]];
  state.currentClipIndex = j;
  let vt = 0; for (let k = 0; k < j; k++) vt += state.clips[k].sourceEnd - state.clips[k].sourceStart;
  state.vt = vt; video.currentTime = state.clips[j].sourceStart;
  relayout(); renderRuler(); updatePlayhead(); updateAllButtons(); snapshot();
}
$('#btn-move-left').onclick  = () => moveCurrent(-1);
$('#btn-move-right').onclick = () => moveCurrent(1);
function toggleMute() {
  state.muted = !state.muted;
  video.muted = state.muted;
  const b = $('#btn-mute');
  b.textContent = state.muted ? '🔇' : '🔊';
  b.classList.toggle('active', state.muted);
  snapshot();
}
$('#btn-mute').onclick = toggleMute;

/* ---------- Overlays: pílulas por trilha ---------- */
function renderOverlayPills() {
  trackText.innerHTML = '<span class="track-label">T</span>';
  trackImage.innerHTML = '<span class="track-label">🖼</span>';
  if (!state.clips.length) return;

  state.overlays.forEach(ov => {
    const track = ov.type === 'text' ? trackText : trackImage;
    const el = document.createElement('div');
    el.className = 'overlay-pill ' + ov.type;
    if (ov.id === state.selectedOverlayId) el.classList.add('selected');
    el.style.left = (PAD_LEFT + ov.tStart * PPS) + 'px';
    el.style.width = Math.max(16, (ov.tEnd - ov.tStart) * PPS) + 'px';

    const label = document.createElement('span');
    label.style.overflow = 'hidden';
    label.style.textOverflow = 'ellipsis';
    label.style.pointerEvents = 'none';
    label.style.whiteSpace = 'nowrap';
    label.textContent = ov.type === 'text' ? (ov.text || '').slice(0, 20) : '🖼';
    el.appendChild(label);

    const lh = document.createElement('div');
    lh.className = 'ov-handle left';
    lh.addEventListener('pointerdown', e => startOverlayDrag(e, ov, 'left'));
    el.appendChild(lh);
    const rh = document.createElement('div');
    rh.className = 'ov-handle right';
    rh.addEventListener('pointerdown', e => startOverlayDrag(e, ov, 'right'));
    el.appendChild(rh);

    el.addEventListener('pointerdown', (e) => {
      if (e.target === lh || e.target === rh) return;
      startOverlayDrag(e, ov, 'move');
    });
    track.appendChild(el);
  });
}
function startOverlayDrag(e, ov, mode) {
  e.preventDefault(); e.stopPropagation();
  state.selectedOverlayId = ov.id;
  renderOverlayPills();
  updateOverlaySelectionVisual();
  const startX = e.clientX;
  const oS = ov.tStart, oE = ov.tEnd;
  let moved = false;

  const onMove = (ev) => {
    if (Math.abs(ev.clientX - startX) > 4) moved = true;
    const dSec = (ev.clientX - startX) / PPS;
    if (mode === 'move') {
      let ns = oS + dSec, ne = oE + dSec;
      const maxT = totalDuration();
      if (ns < 0) { ne -= ns; ns = 0; }
      if (ne > maxT) { ns -= (ne - maxT); ne = maxT; }
      if (ns < 0) ns = 0;
      ov.tStart = ns; ov.tEnd = ne;
    } else if (mode === 'left') {
      ov.tStart = clamp(oS + dSec, 0, oE - MIN_OVERLAY_DURATION);
    } else {
      ov.tEnd = clamp(oE + dSec, oS + MIN_OVERLAY_DURATION, totalDuration());
    }
    renderOverlayPills();
    updateOverlayVisibility();
  };
  const onUp = () => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onUp);
    if (moved) {
      snapshot();
    } else if (mode === 'move') {
      // tap (sem arrasto) — registra para detectar duplo-toque
      registerTap(ov.id, null, () => {
        if (ov.type === 'text') openTextModal(ov);
      });
    }
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
}

/* ---------- Overlays: elementos no preview ---------- */
function renderOverlayElements() {
  overlayLayer.innerHTML = '';
  state.overlays.forEach(ov => {
    let el;
    if (ov.type === 'text') {
      el = document.createElement('div');
      el.className = 'preview-text';
      el.textContent = ov.text;
      el.addEventListener('pointerdown', e => startPreviewMove(e, ov));
    } else {
      el = document.createElement('div');
      el.className = 'preview-image-wrapper';

      const delBtn = document.createElement('button');
      delBtn.className = 'delete-btn';
      delBtn.textContent = '×';
      delBtn.title = 'Apagar imagem';
      delBtn.addEventListener('pointerdown', e => e.stopPropagation());
      delBtn.addEventListener('click', e => {
        e.stopPropagation(); e.preventDefault();
        removeOverlay(ov.id);
      });
      el.appendChild(delBtn);

      const handle = document.createElement('div');
      handle.className = 'resize-handle';
      handle.addEventListener('pointerdown', e => startImageResize(e, ov));
      el.appendChild(handle);

      el.addEventListener('pointerdown', e => {
        if (e.target === handle || e.target === delBtn) return;
        startPreviewMove(e, ov);
      });
    }
    el.dataset.ovId = ov.id;
    overlayLayer.appendChild(el);
  });
  positionOverlays();
  updateOverlayVisibility();
}

function removeOverlay(id) {
  state.overlays = state.overlays.filter(o => o.id !== id);
  if (state.selectedOverlayId === id) state.selectedOverlayId = null;
  renderOverlayPills();
  renderOverlayElements();
  updateOverlayVisibility();
  snapshot();
}

function getVideoContentRect() {
  const r = video.getBoundingClientRect();
  const srcW = state.source && state.source.width ? state.source.width : r.width;
  const srcH = state.source && state.source.height ? state.source.height : r.height;
  const sa = srcW / srcH, ea = r.width / r.height;
  let w, h, x, y;
  if (sa > ea) { w = r.width; h = w / sa; x = r.left; y = r.top + (r.height - h) / 2; }
  else { h = r.height; w = h * sa; x = r.left + (r.width - w) / 2; y = r.top; }
  return { left: x, top: y, width: w, height: h };
}

function positionOverlays() {
  if (!state.overlays.length) return;
  const v = getVideoContentRect();
  const c = videoContainer.getBoundingClientRect();
  overlayLayer.style.left = (v.left - c.left) + 'px';
  overlayLayer.style.top = (v.top - c.top) + 'px';
  overlayLayer.style.width = v.width + 'px';
  overlayLayer.style.height = v.height + 'px';

  state.overlays.forEach(ov => {
    const el = overlayLayer.querySelector(`[data-ov-id="${ov.id}"]`);
    if (!el) return;
    const active = state.vt >= ov.tStart && state.vt <= ov.tEnd;
    el.style.display = active ? 'block' : 'none';
    if (!active) return;
    el.style.left = (ov.x * v.width) + 'px';
    el.style.top = (ov.y * v.height) + 'px';
    if (ov.type === 'text') {
      el.style.fontSize = (ov.fontSize * v.height) + 'px';
      el.style.color = ov.color;
      el.style.background = ov.bg || 'transparent';
    } else {
      const dispW = ov.w * v.width;
      const dispH = ov.h * v.height;
      el.style.width  = dispW + 'px';
      el.style.height = dispH + 'px';
      el.style.backgroundImage = `url('uploads/${ov.srcId}.${ov.ext}')`;
      // recorte: frações → pixels do wrapper
      el.style.backgroundSize =
        `${dispW / (ov.cropW || 1)}px ${dispH / (ov.cropH || 1)}px`;
      el.style.backgroundPosition =
        `${-(ov.cropX || 0) * (dispW / (ov.cropW || 1))}px ` +
        `${-(ov.cropY || 0) * (dispH / (ov.cropH || 1))}px`;
    }
  });
  updateOverlaySelectionVisual();
}

function updateOverlayVisibility() {
  if (!state.overlays.length) return;
  state.overlays.forEach(ov => {
    const el = overlayLayer.querySelector(`[data-ov-id="${ov.id}"]`);
    if (!el) return;
    const active = state.vt >= ov.tStart && state.vt <= ov.tEnd;
    el.style.display = active ? 'block' : 'none';
  });
  positionOverlays();
}

function updateOverlaySelectionVisual() {
  state.overlays.forEach(ov => {
    const el = overlayLayer.querySelector(`[data-ov-id="${ov.id}"]`);
    if (!el) return;
    if (ov.id === state.selectedOverlayId) el.classList.add('selected');
    else el.classList.remove('selected');
  });
}

function startPreviewMove(e, ov) {
  e.preventDefault(); e.stopPropagation();
  state.selectedOverlayId = ov.id;
  updateOverlaySelectionVisual();
  renderOverlayPills();

  const v = getVideoContentRect();
  const startX = e.clientX, startY = e.clientY;
  const oX = ov.x, oY = ov.y;
  let moved = false;

  // Texto não tem largura fixa (auto). Medimos o elemento renderizado
  // para não prender o texto no canto. Imagem usa ov.w / ov.h direto.
  const el = overlayLayer.querySelector(`[data-ov-id="${ov.id}"]`);
  let clampW, clampH;
  if (ov.type === 'text') {
    if (el && el.offsetWidth > 0 && el.offsetHeight > 0) {
      clampW = el.offsetWidth / v.width;
      clampH = el.offsetHeight / v.height;
    } else {
      clampW = 0.3;
      clampH = ov.fontSize || 0.08;
    }
  } else {
    clampW = ov.w;
    clampH = ov.h;
  }

  const onMove = (ev) => {
    if (Math.abs(ev.clientX - startX) > 4 || Math.abs(ev.clientY - startY) > 4) moved = true;
    const dx = (ev.clientX - startX) / v.width;
    const dy = (ev.clientY - startY) / v.height;
    ov.x = clamp(oX + dx, 0, Math.max(0, 1 - clampW));
    ov.y = clamp(oY + dy, 0, Math.max(0, 1 - clampH));
    positionOverlays();
  };
  const onUp = () => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onUp);
    if (moved) snapshot();
    else registerTap(ov.id, null, () => {
      if (ov.type === 'text') openTextModal(ov);
      else if (ov.type === 'image') openImageEditModal(ov);
    });
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
}

function startImageResize(e, ov) {
  e.preventDefault(); e.stopPropagation();
  state.selectedOverlayId = ov.id;
  updateOverlaySelectionVisual();
  renderOverlayPills();

  const v = getVideoContentRect();
  const startX = e.clientX, startY = e.clientY;
  const oW = ov.w, oH = ov.h;
  let moved = false;

  const onMove = (ev) => {
    if (Math.abs(ev.clientX - startX) > 2 || Math.abs(ev.clientY - startY) > 2) moved = true;
    const dw = (ev.clientX - startX) / v.width;
    const dh = (ev.clientY - startY) / v.height;
    ov.w = clamp(oW + dw, 0.03, 1 - ov.x);
    ov.h = clamp(oH + dh, 0.03, 1 - ov.y);
    positionOverlays();
  };
  const onUp = () => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onUp);
    if (moved) snapshot();
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
}

/* ---------- Adicionar texto ---------- */
$('#btn-add-text').onclick = () => {
  if (!state.clips.length) return;
  const vt = getVirtualTime();
  const ov = {
    id: 'ov_' + Math.random().toString(36).slice(2, 10),
    type: 'text',
    text: 'Novo texto',
    tStart: vt,
    tEnd: Math.min(vt + 3, totalDuration()),
    x: 0.1, y: 0.4, w: 0.8,
    fontSize: 0.08,
    color: '#ffffff',
    bg: 'rgba(0,0,0,0.6)',
  };
  state.overlays.push(ov);
  state.selectedOverlayId = ov.id;
  renderOverlayPills(); renderOverlayElements(); updateOverlayVisibility();
  openTextModal(ov, true);
};

/* ---------- Modal de texto ---------- */
function openTextModal(ov, isNew = false) {
  editingOverlayId = ov.id;
  isNewOverlay = isNew;
  $('#modal-text').value = ov.text;
  $('#modal-size').value = Math.round((ov.fontSize || 0.08) * 100);
  $('#modal-color').value = ov.color || '#ffffff';
  $('#modal-bg').value = ov.bg || '';
  textModal.classList.add('open');
  setPlaying(false);
  setTimeout(() => $('#modal-text').focus(), 50);
}
function closeTextModal() {
  textModal.classList.remove('open');
  editingOverlayId = null;
  isNewOverlay = false;
}
$('#modal-cancel').onclick = () => {
  if (isNewOverlay && editingOverlayId) {
    const id = editingOverlayId;
    state.overlays = state.overlays.filter(o => o.id !== id);
    if (state.selectedOverlayId === id) state.selectedOverlayId = null;
    renderOverlayPills(); renderOverlayElements(); updateOverlayVisibility();
    snapshot();
  }
  closeTextModal();
};
$('#modal-save').onclick = () => {
  const ov = state.overlays.find(o => o.id === editingOverlayId);
  if (ov) {
    ov.text = $('#modal-text').value || '';
    ov.fontSize = Math.max(0.01, (parseInt($('#modal-size').value, 10) || 8) / 100);
    ov.color = $('#modal-color').value || '#ffffff';
    ov.bg = $('#modal-bg').value || '';
    renderOverlayPills(); renderOverlayElements(); updateOverlayVisibility();
    snapshot();
  }
  closeTextModal();
};
$('#modal-delete').onclick = () => {
  const id = editingOverlayId;
  state.overlays = state.overlays.filter(o => o.id !== id);
  if (state.selectedOverlayId === id) state.selectedOverlayId = null;
  closeTextModal();
  renderOverlayPills(); renderOverlayElements(); updateOverlayVisibility();
  snapshot();
};

/* ---------- Adicionar imagem ---------- */
$('#btn-add-image').onclick = () => { if (state.clips.length) $('#image-file').click(); };
$('#image-file').onchange = async (e) => {
  const f = e.target.files[0];
  e.target.value = '';
  if (!f) return;

  const localUrl = URL.createObjectURL(f);

  openCropModal({
    srcUrl: localUrl,
    cropX: 0, cropY: 0, cropW: 1, cropH: 1,
    confirmLabel: 'Adicionar',
    showDelete: false,
    onConfirm: async (cx, cy, cw, ch) => {
      const fd = new FormData(); fd.append('image', f);
      const res = await fetch('api/upload_asset.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.error) { alert(data.error); return; }

      const w = 0.3;
      const aspect = data.w > 0 ? data.h / data.w : 1;
      const videoAspect = (state.source.width || 1920) / (state.source.height || 1080);
      const hFrac = clamp(w * aspect / videoAspect, 0.03, 1);

      // ajusta w/h para já refletir o recorte (o que sobra após crop)
      const dispAspect = (cw / ch) * aspect;
      const hFinal = clamp(w / dispAspect * videoAspect, 0.03, 1);

      const ov = {
        id: 'ov_' + Math.random().toString(36).slice(2, 10),
        type: 'image',
        srcId: data.id, ext: data.ext,
        tStart: state.vt,
        tEnd: Math.min(state.vt + 3, totalDuration()),
        x: (1 - w) / 2,
        y: Math.max(0.02, (1 - hFinal) / 2),
        w: w, h: hFinal,
        cropX: cx, cropY: cy, cropW: cw, cropH: ch,
      };
      state.overlays.push(ov);
      state.selectedOverlayId = ov.id;
      renderOverlayPills(); renderOverlayElements(); updateOverlayVisibility();
      snapshot();
    },
    onCancel: () => { URL.revokeObjectURL(localUrl); },
  });
};

function openImageEditModal(ov) {
  openCropModal({
    srcUrl: `uploads/${ov.srcId}.${ov.ext}`,
    cropX: ov.cropX || 0, cropY: ov.cropY || 0,
    cropW: ov.cropW || 1, cropH: ov.cropH || 1,
    confirmLabel: 'Salvar',
    showDelete: true,
    onConfirm: (cx, cy, cw, ch) => {
      // recalcula h para manter a proporção visual correta
      const oldW = ov.w, oldH = ov.h;
      const oldCropAspect = (ov.cropW || 1) / (ov.cropH || 1);
      const newCropAspect = cw / ch;
      const ratio = newCropAspect / oldCropAspect;
      ov.w = clamp(oldW * ratio, 0.03, 1 - ov.x);
      ov.h = oldH;
      ov.cropX = cx; ov.cropY = cy; ov.cropW = cw; ov.cropH = ch;
      renderOverlayPills(); renderOverlayElements(); updateOverlayVisibility();
      snapshot();
    },
    onDelete: () => removeOverlay(ov.id),
  });
}
/* ---------- Atalhos ---------- */
document.addEventListener('keydown', (e) => {
  if (!state.clips.length) return;
  const tag = (e.target.tagName || '').toUpperCase();
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

  if (e.key === 'Escape') {
    if (textModal.classList.contains('open')) { $('#modal-cancel').click(); return; }
    if (cropModal.classList.contains('open')) { $('#crop-cancel').click(); return; }
    state.selectedOverlayId = null;
    updateOverlaySelectionVisual();
    return;
  }

  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
    e.preventDefault(); if (e.shiftKey) doRedo(); else doUndo(); return;
  }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') { e.preventDefault(); doRedo(); return; }
  switch (e.key) {
    case ' ': e.preventDefault(); setPlaying(!state.playing); break;
    case 'ArrowLeft': e.preventDefault(); applySeek(getVirtualTime() - (e.shiftKey ? 1 : 1/getFps())); break;
    case 'ArrowRight': e.preventDefault(); applySeek(getVirtualTime() + (e.shiftKey ? 1 : 1/getFps())); break;
    case 's': case 'S': e.preventDefault(); doSplit(); break;
    case 'm': case 'M': e.preventDefault(); toggleMute(); break;
    case 'Delete': case 'Backspace':
      e.preventDefault();
      if (state.selectedOverlayId) {
        const id = state.selectedOverlayId;
        state.overlays = state.overlays.filter(o => o.id !== id);
        state.selectedOverlayId = null;
        renderOverlayPills(); renderOverlayElements(); updateOverlayVisibility(); snapshot();
      } else doDelete();
      break;
    case '+': case '=': e.preventDefault(); zoomIn(); break;
    case '-': case '_': e.preventDefault(); zoomOut(); break;
  }
});

/* ---------- Export ---------- */
$('#btn-render').onclick = async () => {
  if (!state.source || !state.clips.length) return;
  setPlaying(false);
  $('#btn-render').disabled = true;
  $('#progress-bar').style.display = 'block';
  $('#progress-bar > div').style.width = '0%';
  $('#status').textContent = 'Iniciando exportação...';

  const clips = state.clips.map(c => ({ sourceStart: c.sourceStart, sourceEnd: c.sourceEnd }));
  const overlays = state.overlays.map(o => ({ ...o }));

  const res = await fetch('api/render.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      source_id: state.source.id, source_ext: state.source.ext,
      clips, overlays, muted: state.muted,
    }),
  });
  const data = await res.json();
  if (data.error) { $('#status').textContent = 'Erro: ' + data.error; $('#btn-render').disabled = false; return; }
  pollRender(data.job_id);
};

async function pollRender(id) {
  const res = await fetch('api/status.php?id=' + id);
  const j = await res.json();
  const pct = j.progress || 0;
  $('#progress-bar > div').style.width = pct + '%';
  if (j.status === 'done') {
    $('#status').textContent = 'Pronto!';
    const r = $('#result'); r.src = j.output + '?t=' + Date.now(); r.style.display = 'block';
    $('#btn-render').disabled = false;
  } else if (j.status === 'error') {
    $('#status').textContent = 'Erro: ' + j.error; $('#btn-render').disabled = false;
  } else {
    $('#status').textContent = `Exportando... ${pct}%`;
    setTimeout(() => pollRender(id), 1000);
  }
}

window.addEventListener('resize', () => {
  if (!state.clips.length) return;
  relayout(); renderRuler(); renderOverlayPills(); positionOverlays(); updatePlayhead();
});
/* ---------- Crop modal ---------- */
const cropState = {
  cropX: 0, cropY: 0, cropW: 1, cropH: 1,
  onConfirm: null, onDelete: null, onCancel: null,
};

function openCropModal(opts) {
  cropState.cropX = opts.cropX ?? 0;
  cropState.cropY = opts.cropY ?? 0;
  cropState.cropW = opts.cropW ?? 1;
  cropState.cropH = opts.cropH ?? 1;
  cropState.onConfirm = opts.onConfirm;
  cropState.onDelete  = opts.onDelete  || null;
  cropState.onCancel  = opts.onCancel  || null;

  $('#crop-confirm').textContent = opts.confirmLabel || 'Adicionar';
  $('#crop-delete').style.display = opts.showDelete ? 'block' : 'none';

  cropImg.onload = () => {
    cropModal.classList.add('open');
    requestAnimationFrame(updateCropSelection);
  };
  cropImg.src = opts.srcUrl;
}

function closeCropModal() {
  cropModal.classList.remove('open');
  cropImg.onload = null;
  cropImg.removeAttribute('src');
  cropState.onConfirm = null;
  cropState.onDelete  = null;
  cropState.onCancel  = null;
}

function updateCropSelection() {
  const w = cropImg.clientWidth;
  const h = cropImg.clientHeight;
  cropSel.style.left   = (cropState.cropX * w) + 'px';
  cropSel.style.top    = (cropState.cropY * h) + 'px';
  cropSel.style.width  = (cropState.cropW * w) + 'px';
  cropSel.style.height = (cropState.cropH * h) + 'px';
}

document.querySelectorAll('.crop-handle').forEach(h => {
  h.addEventListener('pointerdown', (e) => {
    e.preventDefault(); e.stopPropagation();
    const corner = h.dataset.corner;
    const startX = e.clientX, startY = e.clientY;
    const oX = cropState.cropX, oY = cropState.cropY;
    const oW = cropState.cropW, oH = cropState.cropH;
    const imgW = cropImg.clientWidth, imgH = cropImg.clientHeight;
    const MIN = 0.05;

    const onMove = (ev) => {
      const dx = (ev.clientX - startX) / imgW;
      const dy = (ev.clientY - startY) / imgH;

      if (corner === 'se') {
        cropState.cropW = Math.max(MIN, Math.min(1 - cropState.cropX, oW + dx));
        cropState.cropH = Math.max(MIN, Math.min(1 - cropState.cropY, oH + dy));
      } else if (corner === 'sw') {
        const newX = Math.max(0, Math.min(oX + oW - MIN, oX + dx));
        cropState.cropW = oX + oW - newX;
        cropState.cropX = newX;
        cropState.cropH = Math.max(MIN, Math.min(1 - cropState.cropY, oH + dy));
      } else if (corner === 'ne') {
        cropState.cropW = Math.max(MIN, Math.min(1 - cropState.cropX, oW + dx));
        const newY = Math.max(0, Math.min(oY + oH - MIN, oY + dy));
        cropState.cropH = oY + oH - newY;
        cropState.cropY = newY;
      } else if (corner === 'nw') {
        const newX = Math.max(0, Math.min(oX + oW - MIN, oX + dx));
        cropState.cropW = oX + oW - newX;
        cropState.cropX = newX;
        const newY = Math.max(0, Math.min(oY + oH - MIN, oY + dy));
        cropState.cropH = oY + oH - newY;
        cropState.cropY = newY;
      }
      updateCropSelection();
    };
    const onUp = () => {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onUp);
    };
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
    document.addEventListener('pointercancel', onUp);
  });
});

cropSel.addEventListener('pointerdown', (e) => {
  if (e.target !== cropSel) return;
  e.preventDefault();
  const startX = e.clientX, startY = e.clientY;
  const oX = cropState.cropX, oY = cropState.cropY;
  const imgW = cropImg.clientWidth, imgH = cropImg.clientHeight;
  const onMove = (ev) => {
    const dx = (ev.clientX - startX) / imgW;
    const dy = (ev.clientY - startY) / imgH;
    cropState.cropX = Math.max(0, Math.min(1 - cropState.cropW, oX + dx));
    cropState.cropY = Math.max(0, Math.min(1 - cropState.cropH, oY + dy));
    updateCropSelection();
  };
  const onUp = () => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onUp);
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
});

$('#crop-cancel').onclick = () => {
  const cb = cropState.onCancel;
  closeCropModal();
  if (cb) cb();
};
$('#crop-reset').onclick = () => {
  cropState.cropX = 0; cropState.cropY = 0; cropState.cropW = 1; cropState.cropH = 1;
  updateCropSelection();
};
$('#crop-confirm').onclick = () => {
  const cb = cropState.onConfirm;
  const vals = { x: cropState.cropX, y: cropState.cropY, w: cropState.cropW, h: cropState.cropH };
  closeCropModal();
  if (cb) cb(vals.x, vals.y, vals.w, vals.h);
};
$('#crop-delete').onclick = () => {
  const cb = cropState.onDelete;
  closeCropModal();
  if (cb) cb();
};
})();

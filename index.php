<?php require_once __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VideoEditor</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin:0; font:14px system-ui, sans-serif; background:#0f0f12; color:#eee; overflow-x:hidden; }
  main { max-width:900px; margin:0 auto; padding:8px; }
  h1 { font-size:1rem; margin:.3rem 0 .6rem; }

  #upload-area { text-align:center; padding:14px; }
  #upload-area input { display:none; }
  #upload-area label {
    display:inline-block; padding:.7rem 1.2rem; background:#2a6df4;
    color:#fff; border-radius:8px; cursor:pointer; font-size:.95rem;
  }
  #info { color:#888; font-size:.8rem; text-align:center; margin:.3rem 0; min-height:1em; }

  #preview-wrap { position: relative; display:none; }
  #preview-wrap.active { display:block; }

  #video-container { position: relative; width: 100%; }
  video#preview {
    width:100%; max-height:46vh; background:#000; border-radius:8px;
    display:block; object-fit: contain;
  }
  #overlay-layer {
    position: absolute; pointer-events: none; overflow: hidden; z-index: 3;
  }
  .preview-text, .preview-image-wrapper {
    position: absolute; pointer-events: auto; cursor: move;
    user-select: none; touch-action: none;
  }
  .preview-text {
    padding: 2px 6px; border-radius: 3px; line-height: 1.15;
    font-family: sans-serif; white-space: pre;
  }
  .preview-text.selected {
    outline: 2px dashed #ffcc00;
    outline-offset: 2px;
  }

  .preview-image-wrapper {
    position: absolute;
    background-repeat: no-repeat;
    background-color: transparent;
   }
  .preview-image-wrapper.selected .preview-image {
    border-color: #ffcc00;
  }
  .resize-handle {
    position: absolute;
    bottom: -11px; right: -11px;
    width: 22px; height: 22px;
    background: #ffcc00;
    border: 2px solid #fff;
    border-radius: 50%;
    cursor: nwse-resize;
    touch-action: none;
    z-index: 10;
    box-shadow: 0 0 6px rgba(0,0,0,.5);
    display: none;
  }
  .preview-image-wrapper.selected .resize-handle {
    display: block;
  }

  #buffering {
    display: none; position: absolute; top: 40%; left: 50%;
    transform: translate(-50%, -50%); background: #000c; color: #fff;
    padding: .5rem .9rem; border-radius: 6px; font-size: .85rem;
    z-index: 6; pointer-events: none;
  }

  .toolbar { display:flex; flex-wrap:wrap; gap:.4rem; margin:.6rem 0; align-items:center; }
  .toolbar button {
    flex:0 0 auto; padding:.5rem .7rem; border:0; border-radius:6px;
    background:#2a2a33; color:#eee; font-size:.85rem; cursor:pointer;
    min-width:38px;
  }
  .toolbar button:active { background:#3a3a45; }
  .toolbar button.primary { background:#2a6df4; color:#fff; }
  .toolbar button.danger  { background:#b02030; color:#fff; }
  .toolbar button.active  { background:#5a4a00; color:#ffcc00; }
  .toolbar button:disabled { opacity:.35; cursor:not-allowed; }

  /* Timecode empilhado */
  .time-block {
    margin-left:auto;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    font-family: ui-monospace, monospace;
    font-variant-numeric: tabular-nums;
    line-height: 1.15;
  }
  .time-current { color:#eee; font-size:.8rem; }
  .time-total   { color:#666; font-size:.7rem; }

  .zoom-group {
    display:inline-flex; align-items:center; gap:2px;
    background:#1a1a22; border-radius:6px; padding:2px;
  }
  .zoom-group button { padding:4px 10px; min-width:32px; background:transparent; }
  #zoom-label {
    font-size:.7rem; color:#aaa; min-width:52px; text-align:center;
    font-variant-numeric:tabular-nums; font-family:ui-monospace, monospace;
  }

  #timeline-wrap {
    position:relative; margin-top:.6rem; background:#15151b;
    border-radius:8px; overflow:hidden; user-select:none;
  }
  #timeline-scroll {
    overflow-x:auto; overflow-y:hidden;
    touch-action: pan-x; -webkit-overflow-scrolling: touch;
  }
  #timeline-scroll::-webkit-scrollbar { height:6px; }
  #timeline-scroll::-webkit-scrollbar-thumb { background:#333; border-radius:3px; }

  #timeline-content { position:relative; height:122px; min-width:100%; }

  #ruler {
    position:absolute; top:0; left:0; height:64px; width:100%;
    background:#1e1e28; border-bottom:1px solid #2a2a35;
    cursor:pointer; touch-action: none;
  }
  .ruler-tick { position:absolute; top:22px; height:42px; width:1px; background:#3a3a45; }
  .ruler-tick.major { top:18px; height:46px; width:1px; background:#5a5a68; }
  .ruler-tick.frame { top:52px; height:12px; width:1px; background:#2a2a35; }
  .ruler-label {
    position:absolute; top:2px; font-size:.7rem; color:#8a8a98;
    transform:translateX(3px); pointer-events:none;
    font-variant-numeric:tabular-nums;
    font-family:ui-monospace, monospace;
  }
  .cut-marker {
    position:absolute; top:0; width:2px; height:64px;
    background:#ff8800; z-index:2; box-shadow:0 0 4px #ff8800aa;
    pointer-events: none;
  }

  /* Trilhas separadas */
  #overlay-track-text {
    position:absolute; top:64px; left:0; height:28px; width:100%;
    background:#181a28;
    border-bottom:1px solid #222530;
  }
  #overlay-track-image {
    position:absolute; top:94px; left:0; height:28px; width:100%;
    background:#1d1828;
  }
  .track-label {
    position:sticky; left:4px; top:0;
    font-size:.6rem; color:#556; font-weight:600;
    pointer-events:none; z-index:1;
    display: inline-block; padding: 1px 4px;
    background: rgba(0,0,0,.4); border-radius: 3px;
  }

  .overlay-pill {
    position: absolute; top:3px; height:22px;
    border-radius: 4px; font-size:.65rem; color:#fff;
    padding: 0 8px; display: flex; align-items: center;
    cursor: pointer; overflow: hidden; white-space: nowrap;
    user-select: none; touch-action: none; box-sizing: border-box;
    min-width: 16px;
  }
  .overlay-pill.text  { background: #2a6df4; border: 1px solid #4a8df4; }
  .overlay-pill.image { background: #7a3ad0; border: 1px solid #9a5af0; }
  .overlay-pill.selected { border-color: #ffcc00; border-width: 2px; }
  .overlay-pill .ov-handle {
    position: absolute; top: 0; width: 8px; height: 100%;
    background: rgba(255,255,255,.25); cursor: ew-resize;
    touch-action: none;
  }
  .overlay-pill .ov-handle.left  { left: 0; }
  .overlay-pill .ov-handle.right { right: 0; }

  #playhead {
    position:absolute; top:0; left:0; width:2px; height:122px;
    background:#ff4444; pointer-events:none; z-index:10;
    box-shadow:0 0 4px #ff444488;
  }
  #playhead .playhead-handle {
    position:absolute; top:22px; left:-13px;
    width:28px; height:28px; background:#ff4444; border:2px solid #fff;
    border-radius:50%; cursor:ew-resize; pointer-events:auto;
    box-shadow:0 0 6px rgba(255,68,68,.8); touch-action: none;
  }

  #export-area { margin-top:.6rem; }
  #progress-bar { height:6px; background:#222; border-radius:3px; overflow:hidden; margin:.4rem 0; display:none; }
  #progress-bar > div { height:100%; width:0; background:#2a6df4; transition:width .3s; }
  #status { color:#aaa; font-size:.8rem; min-height:1.2em; }
  #result { margin-top:.6rem; width:100%; border-radius:6px; display:none; }

  #text-modal {
    display: none; position: fixed; inset: 0; background: #000a;
    z-index: 100; align-items: center; justify-content: center; padding: 16px;
  }
  #text-modal.open { display: flex; }
  #text-modal-inner {
    background: #1a1a22; border-radius: 10px; padding: 16px;
    width: 100%; max-width: 420px; max-height: 90vh; overflow-y: auto;
  }
  #text-modal-inner h3 { margin: 0 0 .8rem; font-size: 1rem; }
  #text-modal-inner label { display: block; margin: .6rem 0 .2rem; font-size: .8rem; color: #aaa; }
  #text-modal-inner input[type=text],
  #text-modal-inner input[type=number] {
    width: 100%; padding: .5rem; border: 1px solid #333; border-radius: 5px;
    background: #0f0f12; color: #eee; font-size: .9rem;
  }
  #text-modal-inner input[type=color] {
    width: 60px; height: 34px; padding: 0; border: 1px solid #333;
    border-radius: 5px; background: transparent;
  }
  #text-modal-inner select {
    width: 100%; padding: .5rem; border: 1px solid #333; border-radius: 5px;
    background: #0f0f12; color: #eee; font-size: .9rem;
  }
  #text-modal-inner .row { display: flex; gap: .6rem; align-items: center; }
  #text-modal-inner .actions { display: flex; gap: .5rem; margin-top: 1rem; }
  #text-modal-inner .actions button { flex: 1; padding: .6rem; }
  #modal-delete { background: #b02030; color: #fff; border: 0; border-radius: 6px; }
  #modal-save   { background: #2a6df4; color: #fff; border: 0; border-radius: 6px; }
  #modal-cancel { background: #2a2a33; color: #eee; border: 0; border-radius: 6px; }
  /* Crop modal */
#crop-modal {
  display: none; position: fixed; inset: 0; background: #000a;
  z-index: 100; align-items: center; justify-content: center; padding: 16px;
}
#crop-modal.open { display: flex; }
#crop-modal-inner {
  background: #1a1a22; border-radius: 10px; padding: 16px;
  width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto;
}
#crop-modal-inner h3 { margin: 0 0 .8rem; font-size: 1rem; }
#crop-stage {
  position: relative; margin: 1rem 0; overflow: hidden;
  background: #000; user-select: none; touch-action: none;
}
#crop-img {
  display: block; width: 100%; height: auto;
  user-select: none; pointer-events: none;
}
#crop-selection {
  position: absolute; box-sizing: border-box;
  border: 2px solid #ffcc00;
  box-shadow: 0 0 0 9999px rgba(0,0,0,0.65);
  cursor: move; touch-action: none;
}
.crop-handle {
  position: absolute; width: 20px; height: 20px;
  background: #ffcc00; border: 2px solid #fff;
  border-radius: 50%; touch-action: none;
  box-sizing: border-box;
}
.crop-handle.nw { top: -10px; left: -10px;  cursor: nwse-resize; }
.crop-handle.ne { top: -10px; right: -10px; cursor: nesw-resize; }
.crop-handle.sw { bottom: -10px; left: -10px;  cursor: nesw-resize; }
.crop-handle.se { bottom: -10px; right: -10px; cursor: nwse-resize; }
#crop-modal-inner .actions { display: flex; gap: .5rem; margin-top: 1rem; }
#crop-modal-inner .actions button { flex: 1; padding: .6rem; border: 0; border-radius: 6px; font-size: .9rem; cursor: pointer; }
#crop-delete { background: #b02030; color: #fff; }
#crop-cancel { background: #2a2a33; color: #eee; }
#crop-reset  { background: #2a2a33; color: #eee; }
#crop-confirm{ background: #2a6df4; color: #fff; }

/* Delete no preview da imagem */
.preview-image-wrapper .delete-btn {
  position: absolute; top: -11px; right: -11px;
  width: 24px; height: 24px; padding: 0;
  background: #b02030; border: 2px solid #fff;
  border-radius: 50%; color: #fff; font-size: 16px;
  line-height: 1; cursor: pointer; z-index: 11;
  display: none; align-items: center; justify-content: center;
}
.preview-image-wrapper.selected .delete-btn { display: flex; }
.preview-image-wrapper.selected {
  outline: 2px solid #ffcc00; outline-offset: 2px;
}
</style>
</head>
<body>
<main>
  <h1>VideoEditor</h1>

  <div id="upload-area">
    <input type="file" id="file" accept="video/*">
    <label for="file">Escolher vídeo</label>
  </div>
  <div id="info"></div>

  <div id="preview-wrap">
    <div id="video-container">
      <video id="preview" playsinline preload="metadata"></video>
      <div id="overlay-layer"></div>
    </div>
    <div id="buffering">⏳ Carregando trecho…</div>

    <div class="toolbar">
      <button id="btn-play"       title="Play/Pause (Espaço)">▶</button>
      <button id="btn-split"      title="Dividir (S)">✂</button>
      <button id="btn-delete"     class="danger" title="Apagar trecho (Del)" disabled>🗑</button>
      <button id="btn-move-left"  title="Mover trecho ←" disabled>⇠</button>
      <button id="btn-move-right" title="Mover trecho →" disabled>⇢</button>
      <button id="btn-mute"       title="Mudo (M)">🔊</button>
      <button id="btn-add-text"   title="Adicionar texto">+T</button>
      <button id="btn-add-image"  title="Adicionar imagem">+🖼</button>
      <input type="file" id="image-file" accept="image/png,image/jpeg,image/jpg,image/gif,image/webp" style="display:none">
      <button id="btn-undo" title="Desfazer (Ctrl+Z)" disabled>↶</button>
      <button id="btn-redo" title="Refazer (Ctrl+Y)" disabled>↷</button>
      <span class="zoom-group">
        <button id="btn-zoom-out" title="Zoom out (-)">−</button>
        <span id="zoom-label">1 px/s</span>
        <button id="btn-zoom-in" title="Zoom in (+)">+</button>
      </span>
      <div class="time-block">
        <div class="time-current" id="time-current">00:00:00:00</div>
        <div class="time-total"   id="time-total">00:00:00:00</div>
      </div>
    </div>

    <div id="timeline-wrap">
      <div id="timeline-scroll">
        <div id="timeline-content">
          <div id="ruler"></div>
          <div id="overlay-track-text">
            <span class="track-label">T</span>
          </div>
          <div id="overlay-track-image">
            <span class="track-label">🖼</span>
          </div>
          <div id="playhead" style="left:0">
            <div class="playhead-handle"></div>
          </div>
        </div>
      </div>
    </div>

    <div id="export-area">
      <button id="btn-render" class="primary">Exportar MP4</button>
      <div id="progress-bar"><div></div></div>
      <div id="status"></div>
    </div>

    <video id="result" controls playsinline></video>
  </div>
</main>
<div id="crop-modal">
  <div id="crop-modal-inner">
    <h3>Recortar imagem</h3>
    <div id="crop-stage">
      <img id="crop-img" alt="">
      <div id="crop-selection">
        <div class="crop-handle nw" data-corner="nw"></div>
        <div class="crop-handle ne" data-corner="ne"></div>
        <div class="crop-handle sw" data-corner="sw"></div>
        <div class="crop-handle se" data-corner="se"></div>
      </div>
    </div>
    <div class="actions">
      <button id="crop-delete">Apagar</button>
      <button id="crop-cancel">Cancelar</button>
      <button id="crop-reset">Resetar</button>
      <button id="crop-confirm">Adicionar</button>
    </div>
  </div>
</div>
<div id="text-modal">
  <div id="text-modal-inner">
    <h3>Editar texto</h3>
    <label>Texto</label>
    <input type="text" id="modal-text" placeholder="Digite aqui">
    <label>Tamanho (% da altura)</label>
    <input type="number" id="modal-size" min="1" max="50" step="1" value="8">
    <div class="row">
      <div>
        <label>Cor</label>
        <input type="color" id="modal-color" value="#ffffff">
      </div>
      <div style="flex:1">
        <label>Fundo</label>
        <select id="modal-bg">
          <option value="">Sem fundo</option>
          <option value="rgba(0,0,0,0.6)">Preto 60%</option>
          <option value="rgba(0,0,0,0.4)">Preto 40%</option>
          <option value="rgba(255,255,255,0.7)">Branco 70%</option>
        </select>
      </div>
    </div>
    <div class="actions">
      <button id="modal-delete">Apagar</button>
      <button id="modal-cancel">Cancelar</button>
      <button id="modal-save">Salvar</button>
    </div>
  </div>
</div>

<script src="assets/js/editor.js"></script>
</body>
</html>

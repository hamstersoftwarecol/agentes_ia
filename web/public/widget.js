/*!
 * Hamster AI Agents — widget de chat para sitios web.
 *
 * Uso:
 *   <script src="https://TU-DOMINIO/widget.js" data-agent="ag_XXXXXXXXXX" async></script>
 *
 * Sin dependencias. Se dibuja dentro de un Shadow DOM, así ni los estilos del sitio rompen el
 * widget ni el widget rompe los del sitio. Todo el texto se inserta con textContent (nunca HTML).
 * API opcional: window.HamsterWidget.open() / .close() / .destroy()
 */
(function () {
  "use strict";

  var script = document.currentScript || document.querySelector("script[data-agent]");
  if (!script) return;

  var agentId = script.getAttribute("data-agent") || "";
  if (!/^ag_[A-Za-z0-9]{10}$/.test(agentId)) {
    console.warn("[Hamster] data-agent inválido:", agentId);
    return;
  }

  var HOST_ID = "hamster-agent-widget";
  if (document.getElementById(HOST_ID)) return; // ya cargado

  // La API vive en el mismo origen que este script, salvo que se indique otra con data-api.
  var origin = script.getAttribute("data-api") || new URL(script.src, location.href).origin;
  var API = origin.replace(/\/$/, "") + "/api/widget/" + agentId;

  var DEFAULT_COLOR = "#b45309";
  var MAX_LENGTH = 1000;
  var REQUEST_TIMEOUT_MS = 60000;

  /* ---------- almacenamiento (puede estar bloqueado: se cae a memoria) ---------- */

  var memory = {};
  function readStore(key) {
    try {
      var value = window.localStorage.getItem(key);
      return value === null ? memory[key] || null : value;
    } catch (e) {
      return memory[key] || null;
    }
  }
  function writeStore(key, value) {
    memory[key] = value;
    try {
      if (value === null) window.localStorage.removeItem(key);
      else window.localStorage.setItem(key, value);
    } catch (e) {
      /* almacenamiento bloqueado: queda en memoria */
    }
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ("0" + x.toString(16)).slice(-2); }).join("");
    return h.slice(0, 8) + "-" + h.slice(8, 12) + "-" + h.slice(12, 16) + "-" + h.slice(16, 20) + "-" + h.slice(20);
  }

  var KEY_VISITOR = "hamster:visitor";
  var KEY_CONVERSATION = "hamster:" + agentId + ":conversation";
  var KEY_SEEN = "hamster:" + agentId + ":seen"; // último mensaje visto con el panel abierto
  var visitorId = readStore(KEY_VISITOR);
  if (!visitorId) {
    visitorId = uuid();
    writeStore(KEY_VISITOR, visitorId);
  }

  /* ---------- red ---------- */

  var PREVIEW = script.hasAttribute("data-preview");

  function request(method, path, body) {
    var controller = "AbortController" in window ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS) : null;
    var headers = { Accept: "application/json", "X-Visitor-Id": visitorId };
    if (body) headers["Content-Type"] = "application/json";

    return fetch(API + path, {
      method: method,
      headers: headers,
      body: body ? JSON.stringify(body) : undefined,
      credentials: "omit", // nunca se envían cookies del sitio
      cache: PREVIEW ? "no-store" : "default", // en la vista previa del panel se ven los cambios al instante
      signal: controller ? controller.signal : undefined,
    })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (!res.ok) {
            var err = new Error((data && data.message) || "http " + res.status);
            err.status = res.status;
            err.code = data && data.code;
            throw err;
          }
          return data;
        });
      })
      .finally(function () { if (timer) clearTimeout(timer); });
  }

  /* ---------- utilidades de interfaz ---------- */

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === "text") node.textContent = attrs[key];
      else if (key.slice(0, 2) === "on") node.addEventListener(key.slice(2), attrs[key]);
      else node.setAttribute(key, attrs[key]);
    });
    (children || []).forEach(function (child) { if (child) node.appendChild(child); });
    return node;
  }

  function icon(path) {
    var ns = "http://www.w3.org/2000/svg";
    var svg = document.createElementNS(ns, "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("width", "24");
    svg.setAttribute("height", "24");
    svg.setAttribute("aria-hidden", "true");
    var p = document.createElementNS(ns, "path");
    p.setAttribute("d", path);
    p.setAttribute("fill", "currentColor");
    svg.appendChild(p);
    return svg;
  }

  var ICON_CHAT = "M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-5 4V6a2 2 0 0 1 1-2z";
  var ICON_CLOSE = "M18.3 5.7a1 1 0 0 0-1.4 0L12 10.6 7.1 5.7a1 1 0 0 0-1.4 1.4L10.6 12l-4.9 4.9a1 1 0 1 0 1.4 1.4L12 13.4l4.9 4.9a1 1 0 0 0 1.4-1.4L13.4 12l4.9-4.9a1 1 0 0 0 0-1.4z";
  var ICON_SEND = "M3.4 20.4 21 12 3.4 3.6 3.4 10l12 2-12 2z";

  /** Negro o blanco según el brillo del color de marca, para que el texto sea legible. */
  function readableOn(hex) {
    var n = parseInt(hex.slice(1), 16);
    var r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6 ? "#111111" : "#ffffff";
  }

  var CSS = [
    ":host{all:initial}",
    "*{box-sizing:border-box}",
    ".root{position:fixed;bottom:20px;z-index:2147483000;font:14px/1.45 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#1c1917}",
    ".root.right{right:20px}.root.left{left:20px}",
    "button{font:inherit;cursor:pointer}",
    "button:focus-visible,textarea:focus-visible{outline:2px solid var(--c);outline-offset:2px}",
    ".launcher{width:56px;height:56px;border-radius:50%;border:0;background:var(--c);color:var(--on);display:grid;place-items:center;box-shadow:0 6px 20px rgba(0,0,0,.25);transition:transform .15s}",
    ".launcher:hover{transform:scale(1.06)}",
    ".panel{position:absolute;bottom:68px;width:min(380px,calc(100vw - 24px));height:min(560px,calc(100vh - 110px));background:#fff;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden;border:1px solid rgba(0,0,0,.08)}",
    ".right .panel{right:0}.left .panel{left:0}",
    ".panel[hidden]{display:none}",
    ".head{background:var(--c);color:var(--on);display:flex;align-items:center;gap:10px;padding:12px 14px}",
    ".avatar{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.25);display:grid;place-items:center;font-weight:700;flex:none;overflow:hidden}",
    ".avatar img{width:100%;height:100%;object-fit:cover}",
    ".title{flex:1;min-width:0}.title b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.title small{opacity:.85;font-size:12px}",
    ".close{background:transparent;border:0;color:inherit;width:32px;height:32px;border-radius:8px;display:grid;place-items:center}",
    ".close:hover{background:rgba(255,255,255,.2)}",
    ".log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:#fafaf9}",
    ".msg{max-width:84%;padding:8px 12px;border-radius:14px;white-space:pre-wrap;overflow-wrap:anywhere}",
    ".msg.assistant{align-self:flex-start;background:#fff;border:1px solid #e7e5e4;border-bottom-left-radius:4px}",
    ".msg.user{align-self:flex-end;background:var(--c);color:var(--on);border-bottom-right-radius:4px}",
    ".msg.human{background:#f5f5f4;border-color:#d6d3d1}",
    ".who{display:block;font-size:11px;font-weight:600;color:#57534e;margin-bottom:2px}",
    ".banner{padding:8px 14px;font-size:12.5px;background:#fef3c7;color:#78350f;border-bottom:1px solid #fde68a}",
    ".banner[hidden]{display:none}",
    ".launcher[data-unread]::after{content:'';position:absolute;top:2px;right:2px;width:12px;height:12px;border-radius:50%;background:#dc2626;border:2px solid #fff}",
    ".launcher{position:relative}",
    ".typing{display:flex;gap:4px;padding:12px}.typing i{width:6px;height:6px;border-radius:50%;background:#a8a29e;animation:b 1s infinite}",
    ".typing i:nth-child(2){animation-delay:.15s}.typing i:nth-child(3){animation-delay:.3s}",
    "@keyframes b{0%,60%,100%{opacity:.3}30%{opacity:1}}",
    "@media (prefers-reduced-motion:reduce){.typing i{animation:none;opacity:.6}.launcher{transition:none}}",
    ".err{align-self:stretch;background:#fee2e2;color:#991b1b;border-radius:10px;padding:8px 12px;font-size:13px}",
    ".err button{margin-left:8px;border:0;background:transparent;color:inherit;text-decoration:underline;padding:0}",
    "form{display:flex;gap:8px;padding:10px;border-top:1px solid #e7e5e4;background:#fff;align-items:flex-end}",
    "textarea{flex:1;resize:none;border:1px solid #d6d3d1;border-radius:12px;padding:9px 12px;font:inherit;max-height:96px;background:#fff;color:inherit}",
    ".send{width:40px;height:40px;border-radius:50%;border:0;background:var(--c);color:var(--on);display:grid;place-items:center;flex:none}",
    ".send:disabled{opacity:.45;cursor:not-allowed}",
    ".foot{text-align:center;font-size:11px;color:#78716c;padding:0 0 8px;background:#fff}",
  ].join("");

  /* ---------- widget ---------- */

  // Respuestas del servidor para las que reintentar no sirve de nada (cupo agotado, sitio no autorizado, plan inactivo…).
  var NO_RETRY = ["conversation_limit", "quota_exceeded", "unavailable", "origin_not_allowed"];

  var POLL_OPEN_MS = 4000;   // panel abierto: una persona puede responder en cualquier momento
  var POLL_CLOSED_MS = 15000; // panel cerrado: solo si hay una persona involucrada (para avisar con un punto rojo)

  var state = {
    open: false, sending: false, config: null,
    conversationId: readStore(KEY_CONVERSATION),
    status: "ai",        // ai | waiting | human | closed (lo informa el servidor)
    agentName: null,     // persona que atiende, si la hay
    lastId: 0,           // último id recibido del servidor (para el sondeo)
    seen: {},            // ids ya dibujados: evita duplicar mensajes propios al sondear
    pollTimer: null,
  };
  var ui = {};

  function scrollDown() { ui.log.scrollTop = ui.log.scrollHeight; }

  function addMessage(role, text, senderName) {
    var human = role === "human";
    var node = el("div", { class: "msg " + (human ? "assistant human" : role) });
    if (human && senderName) node.appendChild(el("span", { class: "who", text: senderName }));
    node.appendChild(document.createTextNode(text)); // texto plano, nunca HTML
    ui.log.appendChild(node);
    scrollDown();
    return node;
  }

  /** Dibuja un mensaje del servidor una sola vez. Devuelve true si era nuevo. */
  function showServerMessage(m) {
    if (state.seen[m.id]) return false;
    state.seen[m.id] = true;
    addMessage(m.role, m.content, m.sender_name);
    return true;
  }

  function applyStatus(status, name) {
    state.status = status || "ai";
    state.agentName = name || state.agentName;
    var text = "";
    if (state.status === "waiting") text = "Un asesor te atenderá en breve. Puedes seguir escribiendo.";
    else if (state.status === "human") text = "Estás conversando con " + (state.agentName || "una persona del equipo") + ".";
    else if (state.status === "closed") text = "Esta conversación terminó. Escribe para empezar una nueva.";
    ui.banner.textContent = text;
    ui.banner.hidden = !text;
    schedulePoll();
  }

  /* ---------- sondeo de respuestas de una persona ---------- */

  function schedulePoll() {
    clearTimeout(state.pollTimer);
    state.pollTimer = null;
    if (!state.conversationId || state.status === "closed") return;
    var interval = state.open ? POLL_OPEN_MS : (state.status === "waiting" || state.status === "human" ? POLL_CLOSED_MS : 0);
    if (!interval) return;
    state.pollTimer = setTimeout(poll, interval);
  }

  function poll() {
    if (!state.conversationId) return;
    request("GET", "/conversations/" + state.conversationId + "/messages?after=" + state.lastId)
      .then(function (data) {
        var fresh = 0;
        data.messages.forEach(function (m) {
          state.lastId = Math.max(state.lastId, m.id);
          if (showServerMessage(m)) fresh++;
        });
        if (state.open) writeStore(KEY_SEEN, String(state.lastId));
        else if (fresh) ui.launcher.setAttribute("data-unread", "");
        applyStatus(data.status, data.agent_name);
      })
      .catch(function (err) {
        if (err.status === 404) { forgetConversation(); return; }
        schedulePoll(); // error de red: se reintenta
      });
  }

  function forgetConversation() {
    state.conversationId = null;
    state.status = "ai";
    writeStore(KEY_CONVERSATION, null);
    if (ui.banner) applyStatus("ai");
  }

  function setTyping(on) {
    if (ui.typing) { ui.typing.remove(); ui.typing = null; }
    if (on) {
      ui.typing = el("div", { class: "msg assistant typing", "aria-label": "El asistente está escribiendo" }, [el("i"), el("i"), el("i")]);
      ui.log.appendChild(ui.typing);
      scrollDown();
    }
  }

  function showError(text, retry) {
    clearError();
    var children = [];
    if (retry) children.push(el("button", { type: "button", text: "Reintentar", onclick: retry }));
    ui.error = el("div", { class: "err", role: "alert" }, [el("span", { text: text })].concat(children));
    ui.log.appendChild(ui.error);
    scrollDown();
  }
  function clearError() { if (ui.error) { ui.error.remove(); ui.error = null; } }

  function friendly(err) {
    if (NO_RETRY.indexOf(err.code) !== -1 || err.status === 503 || err.status === 403) return err.message;
    // El 429 del limitador de la API llega con texto genérico en inglés: se muestra uno propio.
    if (err.status === 429) return "Estás escribiendo muy rápido. Espera unos segundos e inténtalo de nuevo.";
    if (err.name === "AbortError") return "El asistente tardó demasiado en responder.";
    return "No pudimos enviar tu mensaje. Revisa tu conexión e inténtalo de nuevo.";
  }

  function updateSendState() {
    ui.send.disabled = state.sending || ui.input.value.trim() === "";
  }

  function send(text, isRetry) {
    if (state.sending) return Promise.resolve();
    state.sending = true;
    clearError();
    if (!isRetry) addMessage("user", text);
    // Si ya la atiende una persona (o espera una), la IA no contesta: no hay "escribiendo…".
    setTyping(state.status === "ai" || state.status === "closed");
    updateSendState();

    return request("POST", "/messages", {
      message: text,
      conversation_id: state.conversationId || undefined,
      page_url: location.origin + location.pathname,
    })
      .then(function (data) {
        state.conversationId = data.conversation_id;
        writeStore(KEY_CONVERSATION, data.conversation_id);
        setTyping(false);
        // Mensajes propios: se marcan como ya dibujados para que el sondeo no los repita.
        if (data.user_message_id) state.seen[data.user_message_id] = true;
        if (data.message) {
          state.seen[data.message.id] = true;
          addMessage("assistant", data.message.content);
        }
        applyStatus(data.status);
      })
      .catch(function (err) {
        setTyping(false);
        if (err.status === 404 && state.conversationId) {
          // La conversación guardada ya no existe: se empieza una nueva y se reintenta una vez.
          forgetConversation();
          state.sending = false;
          return send(text, true);
        }
        showError(friendly(err), NO_RETRY.indexOf(err.code) !== -1 ? null : function () { send(text, true); });
      })
      .finally(function () {
        state.sending = false;
        updateSendState();
        if (state.open) ui.input.focus();
      });
  }

  /** Restaura la conversación guardada sin abrir el panel; si una persona respondió mientras no estaba, avisa con un punto rojo. */
  function loadHistory() {
    var lastSeen = parseInt(readStore(KEY_SEEN) || "0", 10);
    request("GET", "/conversations/" + state.conversationId + "/messages")
      .then(function (data) {
        var unread = false;
        data.messages.forEach(function (m) {
          state.lastId = Math.max(state.lastId, m.id);
          showServerMessage(m);
          if (m.role === "human" && m.id > lastSeen) unread = true;
        });
        if (unread && !state.open) ui.launcher.setAttribute("data-unread", "");
        applyStatus(data.status, data.agent_name);
      })
      .catch(function (err) {
        if (err.status === 404) forgetConversation();
      });
  }

  function setOpen(open) {
    state.open = open;
    ui.panel.hidden = !open;
    ui.launcher.setAttribute("aria-expanded", String(open));
    ui.launcher.setAttribute("aria-label", (open ? "Cerrar chat con " : "Abrir chat con ") + state.config.name);
    if (open) {
      ui.launcher.removeAttribute("data-unread");
      writeStore(KEY_SEEN, String(state.lastId)); // lo que había hasta ahora ya se vio
      ui.input.focus();
      scrollDown();
      if (state.conversationId) poll(); // ponerse al día al abrir
    } else {
      ui.launcher.focus();
    }
    schedulePoll();
  }

  function build(config) {
    state.config = config;
    var color = /^#[0-9a-f]{6}$/i.test(config.color || "") ? config.color : DEFAULT_COLOR;
    var side = config.position === "left" ? "left" : "right";

    var host = el("div", { id: HOST_ID });
    var shadow = host.attachShadow({ mode: "open" });
    shadow.appendChild(el("style", { text: CSS }));

    var avatar = el("div", { class: "avatar" });
    if (config.logo_url && /^https?:\/\//.test(config.logo_url)) {
      avatar.appendChild(el("img", { src: config.logo_url, alt: "" }));
    } else {
      avatar.textContent = (config.company || config.name || "?").charAt(0).toUpperCase();
    }

    ui.close = el("button", { class: "close", type: "button", "aria-label": "Cerrar chat", onclick: function () { setOpen(false); } });
    ui.close.appendChild(icon(ICON_CLOSE));

    ui.banner = el("div", { class: "banner", role: "status", hidden: "" });
    ui.log = el("div", { class: "log", role: "log", "aria-live": "polite" });
    if (config.welcome_message) ui.log.appendChild(el("div", { class: "msg assistant", text: config.welcome_message }));

    ui.input = el("textarea", { rows: "1", maxlength: String(MAX_LENGTH), placeholder: "Escribe tu mensaje…", "aria-label": "Mensaje" });
    ui.send = el("button", { class: "send", type: "submit", "aria-label": "Enviar mensaje", disabled: "" });
    ui.send.appendChild(icon(ICON_SEND));

    var form = el("form", {}, [ui.input, ui.send]);
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var text = ui.input.value.trim();
      if (!text || state.sending) return;
      ui.input.value = "";
      ui.input.style.height = "auto";
      send(text, false);
    });
    ui.input.addEventListener("input", function () {
      ui.input.style.height = "auto";
      ui.input.style.height = Math.min(ui.input.scrollHeight, 96) + "px";
      updateSendState();
    });
    ui.input.addEventListener("keydown", function (e) {
      if (e.key === "Enter" && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
      if (e.key === "Escape") setOpen(false);
    });

    ui.panel = el("div", { class: "panel", role: "dialog", "aria-label": "Chat con " + config.name, hidden: "" }, [
      el("div", { class: "head" }, [
        avatar,
        el("div", { class: "title" }, [el("b", { text: config.name }), el("small", { text: config.company ? "Asistente de " + config.company : "Asistente virtual" })]),
        ui.close,
      ]),
      ui.banner,
      ui.log,
      form,
      el("div", { class: "foot", text: "Con tecnología de Hamster AI Agents" }),
    ]);

    ui.launcher = el("button", {
      class: "launcher", type: "button", "aria-expanded": "false", "aria-label": "Abrir chat con " + config.name,
      onclick: function () { setOpen(!state.open); },
    });
    ui.launcher.appendChild(icon(ICON_CHAT));

    var root = el("div", { class: "root " + side }, [ui.panel, ui.launcher]);
    root.style.setProperty("--c", color);
    root.style.setProperty("--on", readableOn(color));
    shadow.appendChild(root);
    document.body.appendChild(host);

    // Con una conversación guardada se consulta su estado ya, para avisar (punto rojo) si una persona respondió mientras no estaba.
    if (state.conversationId) loadHistory();

    window.HamsterWidget = {
      open: function () { setOpen(true); },
      close: function () { setOpen(false); },
      destroy: function () { clearTimeout(state.pollTimer); host.remove(); delete window.HamsterWidget; },
    };
  }

  function start() {
    request("GET", "/config")
      .then(function (config) {
        if (!config.active) return; // agente desactivado: el widget no aparece
        build(config);
      })
      .catch(function (err) { console.warn("[Hamster] no se pudo cargar el widget:", err.message); });
  }

  if (document.body) start();
  else document.addEventListener("DOMContentLoaded", start);
})();

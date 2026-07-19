const API_BASE = window.location.hostname === "localhost"
  ? "http://localhost:8000/api"
  : "https://fullstack-backend-7kas.onrender.com/api";

const TOKEN_KEY = "kk_token";

function getToken() { return localStorage.getItem(TOKEN_KEY); }
function setToken(t) { localStorage.setItem(TOKEN_KEY, t); }
function clearToken() { localStorage.removeItem(TOKEN_KEY); }

async function api(path, options = {}) {
  const token = getToken();
  const headers = { "Content-Type": "application/json", ...(options.headers || {}) };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = await fetch(`${API_BASE}${path}`, { ...options, headers });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || "Request failed.");
  return data;
}

const BELTS = ["white", "yellow", "orange", "green", "blue", "purple", "brown", "black"];

async function init() {
  document.getElementById("admin-login-form").addEventListener("submit", handleLogin);
  document.getElementById("admin-logout").addEventListener("click", handleLogout);
  document.getElementById("add-video-form").addEventListener("submit", handleAddVideo);
  document.getElementById("add-event-form").addEventListener("submit", handleAddEvent);
  wireMobileMenu();

  const token = getToken();
  if (!token) return showLoginGate();

  try {
    const { user } = await api("/auth/me");
    if (!user.isAdmin) {
      showLoginGate("Signed in, but this account doesn't have admin access.");
      return;
    }
    showDashboard();
  } catch {
    clearToken();
    showLoginGate();
  }
}

function showLoginGate(error) {
  document.getElementById("admin-login-gate").classList.remove("hidden");
  document.getElementById("admin-dashboard").classList.add("hidden");
  document.getElementById("admin-logout").classList.add("hidden");
  const errEl = document.getElementById("admin-login-error");
  errEl.textContent = error || "";
  errEl.classList.toggle("hidden", !error);
}

function showDashboard() {
  document.getElementById("admin-login-gate").classList.add("hidden");
  document.getElementById("admin-dashboard").classList.remove("hidden");
  document.getElementById("admin-logout").classList.remove("hidden");
  loadContent();
  loadVideos();
  loadEvents();
}

async function handleLogin(e) {
  e.preventDefault();
  const email = document.getElementById("admin-email").value.trim();
  const password = document.getElementById("admin-password").value;
  try {
    const data = await api("/auth/login", { method: "POST", body: JSON.stringify({ email, password }) });
    if (!data.user.isAdmin) {
      showLoginGate("That account doesn't have admin access.");
      return;
    }
    setToken(data.token);
    showDashboard();
  } catch (err) {
    showLoginGate(err.message);
  }
}

async function handleLogout() {
  try { await api("/auth/logout", { method: "POST" }); } catch {}
  clearToken();
  showLoginGate();
}

/* ---------------- CONTENT EDITOR ---------------- */

async function loadContent() {
  const list = document.getElementById("content-list");
  list.innerHTML = `<p class="loading-note">Loading…</p>`;
  try {
    const content = await api("/content");
    list.innerHTML = "";
    Object.entries(content).forEach(([key, value]) => {
      const row = document.createElement("div");
      row.className = "content-row";
      row.innerHTML = `
        <span class="content-row-key">${escapeHTML(key)}</span>
        <textarea rows="2">${escapeHTML(value)}</textarea>
        <div class="row-actions">
          <button class="btn btn-primary" type="button">Save</button>
          <span class="save-status" style="font-size:12px;color:var(--green-belt);"></span>
        </div>
      `;
      const textarea = row.querySelector("textarea");
      const saveBtn = row.querySelector("button");
      const status = row.querySelector(".save-status");
      saveBtn.addEventListener("click", async () => {
        saveBtn.disabled = true;
        try {
          await api("/admin/content", { method: "PUT", body: JSON.stringify({ key, value: textarea.value }) });
          status.textContent = "Saved";
          setTimeout(() => (status.textContent = ""), 2000);
        } catch (err) {
          status.style.color = "var(--red)";
          status.textContent = err.message;
        } finally {
          saveBtn.disabled = false;
        }
      });
      list.appendChild(row);
    });
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

/* ---------------- VIDEO MANAGER ---------------- */

async function loadVideos() {
  const list = document.getElementById("video-list");
  list.innerHTML = `<p class="loading-note">Loading…</p>`;
  try {
    const videos = await api("/videos");
    list.innerHTML = "";
    videos.forEach((v) => list.appendChild(renderVideoRow(v)));
    if (videos.length === 0) list.innerHTML = `<p class="loading-note">No videos yet.</p>`;
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

function renderVideoRow(v) {
  const row = document.createElement("div");
  row.className = "video-row";
  row.innerHTML = `
    <div class="video-row-grid">
      <label><span class="field-label">Title</span><input data-f="title" value="${escapeHTML(v.title)}" /></label>
      <label><span class="field-label">Belt</span>
        <select data-f="belt">${BELTS.map((b) => `<option value="${b}" ${b === v.belt ? "selected" : ""}>${b}</option>`).join("")}</select>
      </label>
      <label><span class="field-label">Lesson #</span><input data-f="lesson" type="number" min="1" value="${v.lesson}" /></label>
      <label><span class="field-label">Type</span>
        <select data-f="type">${["Kihon","Kata","Kumite","Bunkai","Conditioning"].map((t) => `<option ${t === v.type ? "selected" : ""}>${t}</option>`).join("")}</select>
      </label>
      <label><span class="field-label">Duration</span><input data-f="duration" value="${escapeHTML(v.duration)}" /></label>
      <label><span class="field-label">Instructor</span><input data-f="instructor" value="${escapeHTML(v.instructor)}" /></label>
      <label style="grid-column:1/-1;"><span class="field-label">Caption</span><input data-f="caption" value="${escapeHTML(v.caption || "")}" /></label>
      <label style="grid-column:1/-1;"><span class="field-label">Video URL</span><input data-f="videoUrl" value="${escapeHTML(v.videoUrl || "")}" /></label>
      <label class="field-checkbox"><input type="checkbox" data-f="premium" ${v.premium ? "checked" : ""} /> Premium</label>
    </div>
    <div class="row-actions">
      <button class="btn btn-primary" data-action="save">Save</button>
      <button class="btn btn-danger" data-action="delete">Delete</button>
      <span class="save-status" style="font-size:12px;color:var(--green-belt);"></span>
    </div>
  `;

  row.querySelector('[data-action="save"]').addEventListener("click", async () => {
    const status = row.querySelector(".save-status");
    const get = (f) => row.querySelector(`[data-f="${f}"]`);
    const payload = {
      title: get("title").value,
      belt: get("belt").value,
      lesson: parseInt(get("lesson").value, 10) || 1,
      type: get("type").value,
      duration: get("duration").value,
      instructor: get("instructor").value,
      caption: get("caption").value,
      videoUrl: get("videoUrl").value,
      premium: get("premium").checked,
    };
    try {
      await api(`/admin/videos/${v.id}`, { method: "PUT", body: JSON.stringify(payload) });
      status.style.color = "var(--green-belt)";
      status.textContent = "Saved";
      setTimeout(() => (status.textContent = ""), 2000);
    } catch (err) {
      status.style.color = "var(--red)";
      status.textContent = err.message;
    }
  });

  row.querySelector('[data-action="delete"]').addEventListener("click", async () => {
    if (!confirm(`Delete "${v.title}"? This can't be undone.`)) return;
    try {
      await api(`/admin/videos/${v.id}`, { method: "DELETE" });
      row.remove();
    } catch (err) {
      alert(err.message);
    }
  });

  return row;
}

async function handleAddVideo(e) {
  e.preventDefault();
  const val = (id) => document.getElementById(id).value;
  const payload = {
    title: val("nv-title"),
    belt: val("nv-belt"),
    lesson: parseInt(val("nv-lesson"), 10) || 1,
    type: val("nv-type"),
    duration: val("nv-duration"),
    instructor: val("nv-instructor"),
    caption: val("nv-caption"),
    videoUrl: val("nv-url"),
    premium: document.getElementById("nv-premium").checked,
  };
  try {
    await api("/admin/videos", { method: "POST", body: JSON.stringify(payload) });
    e.target.reset();
    document.getElementById("nv-premium").checked = true;
    loadVideos();
  } catch (err) {
    alert(err.message);
  }
}

/* ---------------- EVENTS MANAGER ---------------- */

async function loadEvents() {
  const list = document.getElementById("event-list");
  list.innerHTML = `<p class="loading-note">Loading…</p>`;
  try {
    const events = await api("/events");
    list.innerHTML = "";
    events.forEach((ev) => list.appendChild(renderEventRow(ev)));
    if (events.length === 0) list.innerHTML = `<p class="loading-note">No events yet.</p>`;
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

function renderEventRow(ev) {
  const row = document.createElement("div");
  row.className = "video-row";
  row.innerHTML = `
    <div class="video-row-grid">
      <label><span class="field-label">Title</span><input data-f="title" value="${escapeHTML(ev.title)}" /></label>
      <label><span class="field-label">Date</span><input data-f="eventDate" type="date" value="${escapeHTML(ev.eventDate || "")}" /></label>
      <label><span class="field-label">Location</span><input data-f="location" value="${escapeHTML(ev.location || "")}" /></label>
      <label style="grid-column:1/-1;"><span class="field-label">Description</span><input data-f="description" value="${escapeHTML(ev.description || "")}" /></label>
      <label style="grid-column:1/-1;"><span class="field-label">Image URL</span><input data-f="imageUrl" value="${escapeHTML(ev.imageUrl || "")}" /></label>
      <label style="grid-column:1/-1;"><span class="field-label">Link URL</span><input data-f="linkUrl" value="${escapeHTML(ev.linkUrl || "")}" /></label>
    </div>
    <div class="row-actions">
      <button class="btn btn-primary" data-action="save">Save</button>
      <button class="btn btn-danger" data-action="delete">Delete</button>
      <span class="save-status" style="font-size:12px;color:var(--green-belt);"></span>
    </div>
  `;

  row.querySelector('[data-action="save"]').addEventListener("click", async () => {
    const status = row.querySelector(".save-status");
    const get = (f) => row.querySelector(`[data-f="${f}"]`).value;
    const payload = {
      title: get("title"),
      eventDate: get("eventDate"),
      location: get("location"),
      description: get("description"),
      imageUrl: get("imageUrl"),
      linkUrl: get("linkUrl"),
    };
    try {
      await api(`/admin/events/${ev.id}`, { method: "PUT", body: JSON.stringify(payload) });
      status.style.color = "var(--green-belt)";
      status.textContent = "Saved";
      setTimeout(() => (status.textContent = ""), 2000);
    } catch (err) {
      status.style.color = "var(--red)";
      status.textContent = err.message;
    }
  });

  row.querySelector('[data-action="delete"]').addEventListener("click", async () => {
    if (!confirm(`Delete "${ev.title}"? This can't be undone.`)) return;
    try {
      await api(`/admin/events/${ev.id}`, { method: "DELETE" });
      row.remove();
    } catch (err) {
      alert(err.message);
    }
  });

  return row;
}

async function handleAddEvent(e) {
  e.preventDefault();
  const val = (id) => document.getElementById(id).value;
  const payload = {
    title: val("ne-title"),
    eventDate: val("ne-date"),
    location: val("ne-location"),
    description: val("ne-description"),
    imageUrl: val("ne-image"),
    linkUrl: val("ne-link"),
  };
  try {
    await api("/admin/events", { method: "POST", body: JSON.stringify(payload) });
    e.target.reset();
    loadEvents();
  } catch (err) {
    alert(err.message);
  }
}

function escapeHTML(str) {
  const div = document.createElement("div");
  div.textContent = str ?? "";
  return div.innerHTML;
}

function wireMobileMenu() {
  const menuToggle = document.getElementById("menu-toggle");
  const navLinks = document.getElementById("nav-links");
  if (!menuToggle || !navLinks) return;

  function setMenuOpen(open) {
    navLinks.classList.toggle("open", open);
    menuToggle.classList.toggle("active", open);
    menuToggle.setAttribute("aria-expanded", String(open));
  }

  menuToggle.addEventListener("click", () => setMenuOpen(!navLinks.classList.contains("open")));

  navLinks.addEventListener("click", (e) => {
    if (e.target.closest("button, a")) setMenuOpen(false);
  });

  document.addEventListener("click", (e) => {
    if (navLinks.classList.contains("open") && !navLinks.contains(e.target) && !menuToggle.contains(e.target)) {
      setMenuOpen(false);
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") setMenuOpen(false);
  });

  window.addEventListener("resize", () => {
    if (window.innerWidth > 860) setMenuOpen(false);
  });
}

document.addEventListener("DOMContentLoaded", init);
const API_BASE = window.location.hostname === "localhost"
  ? "http://localhost:8000/api"
  : "https://fullstack-backend-7kas.onrender.com/api";

const TOKEN_KEY = "kk_token";

function getToken() { return sessionStorage.getItem(TOKEN_KEY); }
function setToken(t) { sessionStorage.setItem(TOKEN_KEY, t); }
function clearToken() { sessionStorage.removeItem(TOKEN_KEY); }

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
let currentAdmin = null;

async function init() {
  console.log("IKKO Admin build: 2026-08-01-sectioned-accordion-v1");
  document.getElementById("admin-login-form").addEventListener("submit", handleLogin);
  document.getElementById("admin-logout").addEventListener("click", handleLogout);
  document.getElementById("add-video-form").addEventListener("submit", handleAddVideo);
  document.getElementById("add-event-form").addEventListener("submit", handleAddEvent);
  document.getElementById("user-search-form").addEventListener("submit", handleUserSearch);
  document.getElementById("add-guide-form").addEventListener("submit", handleAddGuide);
  document.getElementById("add-announcement-form").addEventListener("submit", handleAddAnnouncement);
  document.getElementById("add-live-form").addEventListener("submit", handleAddLiveSession);
  document.getElementById("add-resource-form").addEventListener("submit", handleAddResource);
  document.getElementById("add-account-form").addEventListener("submit", handleAddAccount);
  wireMobileMenu();
  wireAreaChooser();
  wireLazyAccordions();

  const token = getToken();
  if (!token) return showLoginGate();

  try {
    const { user } = await api("/auth/me");
    if (!user.isAdmin) {
      showLoginGate("Signed in, but this account doesn't have admin access.");
      return;
    }
    currentAdmin = user;
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
  document.getElementById("area-card-accounts").classList.toggle("hidden", !currentAdmin.isSuperAdmin);
  document.getElementById("user-search-results").innerHTML = "";
  showAreaChooser();
}

function showAreaChooser() {
  document.getElementById("area-chooser").classList.remove("hidden");
  document.getElementById("area-breadcrumb").classList.add("hidden");
  document.querySelectorAll(".admin-area").forEach((el) => el.classList.add("hidden"));
}

const AREA_LABELS = { public: "Public Landing Page", dashboard: "Student Dashboard", accounts: "Accounts & Staff" };

function showArea(area) {
  document.getElementById("area-chooser").classList.add("hidden");
  document.getElementById("area-breadcrumb").classList.remove("hidden");
  document.getElementById("area-heading").textContent = AREA_LABELS[area] || "";
  document.querySelectorAll(".admin-area").forEach((el) => el.classList.add("hidden"));
  document.getElementById("area-" + area).classList.remove("hidden");
}

function wireAreaChooser() {
  document.querySelectorAll(".area-card").forEach((card) => {
    card.addEventListener("click", () => showArea(card.dataset.area));
  });
  document.getElementById("back-to-chooser").addEventListener("click", showAreaChooser);
}

/**
 * Each admin section only fetches its data the first time it's actually
 * opened, not all at once on login -- keeps the admin panel responsive as
 * more videos/guides/announcements/etc. accumulate over time, and avoids
 * firing seven API calls before the admin has even chosen what to manage.
 */
function wireLazyAccordions() {
  const loaders = {
    "acc-content": loadContent,
    "acc-videos": loadVideos,
    "acc-events": loadEvents,
    "acc-guides": loadGuides,
    "acc-announcements": loadAnnouncements,
    "acc-live": loadLiveSessions,
    "acc-resources": loadResources,
    "acc-staff": loadStaffDirectory,
  };
  Object.entries(loaders).forEach(([id, loadFn]) => {
    const details = document.getElementById(id);
    if (!details) return;
    details.addEventListener("toggle", () => {
      if (details.open && !details.dataset.loaded) {
        details.dataset.loaded = "true";
        loadFn();
      }
    });
  });
}

let pendingAdminEmail = null;

async function handleLogin(e) {
  e.preventDefault();
  const email = document.getElementById("admin-email").value.trim();
  const password = document.getElementById("admin-password").value;
  try {
    const data = await api("/auth/login", { method: "POST", body: JSON.stringify({ email, password }) });
    pendingAdminEmail = data.email;
    document.getElementById("admin-otp-email").textContent = data.email;
    document.getElementById("admin-login-gate").classList.add("hidden");
    document.getElementById("admin-otp-gate").classList.remove("hidden");
    document.getElementById("admin-otp-code").focus();
  } catch (err) {
    showLoginGate(err.message);
  }
}

async function handleOtpSubmit(e) {
  e.preventDefault();
  const code = document.getElementById("admin-otp-code").value.trim();
  const errEl = document.getElementById("admin-otp-error");
  errEl.classList.add("hidden");
  try {
    const data = await api("/auth/login-verify", { method: "POST", body: JSON.stringify({ email: pendingAdminEmail, code }) });
    if (!data.user.isAdmin) {
      showLoginGate("That account doesn't have admin access.");
      return;
    }
    currentAdmin = data.user;
    setToken(data.token);
    showDashboard();
  } catch (err) {
    errEl.textContent = err.message;
    errEl.classList.remove("hidden");
  }
}

async function handleResendAdminOtp() {
  const errEl = document.getElementById("admin-otp-error");
  try {
    await api("/auth/login-resend-otp", { method: "POST", body: JSON.stringify({ email: pendingAdminEmail }) });
    errEl.style.color = "var(--green-belt)";
    errEl.textContent = "A new code is on its way.";
    errEl.classList.remove("hidden");
  } catch (err) {
    errEl.style.color = "var(--red)";
    errEl.textContent = err.message;
    errEl.classList.remove("hidden");
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

/* ---------------- STUDENT BELT & GRADING ---------------- */

async function handleUserSearch(e) {
  e.preventDefault();
  const q = document.getElementById("user-search-input").value.trim();
  const list = document.getElementById("user-search-results");
  list.innerHTML = `<p class="loading-note">Searching…</p>`;
  try {
    const users = await api(`/admin/users?q=${encodeURIComponent(q)}`);
    list.innerHTML = "";
    if (users.length === 0) list.innerHTML = `<p class="loading-note">No matching students.</p>`;
    users.forEach((u) => list.appendChild(renderUserRow(u)));
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

function renderUserRow(u) {
  const row = document.createElement("div");
  row.className = "video-row";
  const isStaff = u.role === "admin" || u.role === "super_admin";
  const locked = isStaff && !currentAdmin.isSuperAdmin;
  row.innerHTML = `
    <div style="font-size:14px;margin-bottom:8px;">
      <strong>${escapeHTML(u.name)}</strong> · ${escapeHTML(u.email)} · ${escapeHTML(u.subscriptionTier || "no plan")}
      ${isStaff ? ` · <span style="color:var(--gold);">${escapeHTML(u.role.replace("_", " "))}</span>` : ""}
    </div>
    ${locked ? `<p style="color:var(--muted);font-size:13px;">Only a super admin can edit staff accounts.</p>` : `
    <div class="video-row-grid">
      <label><span class="field-label">Belt</span>
        <select data-f="currentBelt">${BELTS.map((b) => `<option value="${b}" ${b === u.currentBelt ? "selected" : ""}>${b}</option>`).join("")}</select>
      </label>
      <label><span class="field-label">Stripes</span><input data-f="stripes" type="number" min="0" max="4" value="${u.stripes ?? 0}" /></label>
      <label><span class="field-label">Target belt</span>
        <select data-f="targetBelt"><option value="">—</option>${BELTS.map((b) => `<option value="${b}" ${b === u.targetBelt ? "selected" : ""}>${b}</option>`).join("")}</select>
      </label>
      <label><span class="field-label">Next grading date</span><input data-f="nextGradingDate" type="date" value="${escapeHTML(u.nextGradingDate || "")}" /></label>
    </div>
    <div class="row-actions">
      <button class="btn btn-primary" data-action="save">Save</button>
      <span class="save-status" style="font-size:12px;color:var(--green-belt);"></span>
    </div>
    `}
  `;

  if (locked) return row;

  row.querySelector('[data-action="save"]').addEventListener("click", async () => {
    const status = row.querySelector(".save-status");
    const get = (f) => row.querySelector(`[data-f="${f}"]`).value;
    const payload = {
      currentBelt: get("currentBelt"),
      stripes: parseInt(get("stripes"), 10) || 0,
      targetBelt: get("targetBelt"),
      nextGradingDate: get("nextGradingDate"),
    };
    try {
      await api(`/admin/users/${u.id}/progress`, { method: "PUT", body: JSON.stringify(payload) });
      status.style.color = "var(--green-belt)";
      status.textContent = "Saved";
      setTimeout(() => (status.textContent = ""), 2000);
    } catch (err) {
      status.style.color = "var(--red)";
      status.textContent = err.message;
    }
  });

  return row;
}

/* ---------------- TRAINING GUIDES ---------------- */

async function loadGuides() {
  const list = document.getElementById("guide-list");
  list.innerHTML = `<p class="loading-note">Loading…</p>`;
  try {
    const guides = await api("/guides");
    list.innerHTML = "";
    guides.forEach((g) => list.appendChild(renderGuideRow(g)));
    if (guides.length === 0) list.innerHTML = `<p class="loading-note">No guides yet.</p>`;
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

function renderGuideRow(g) {
  const row = document.createElement("div");
  row.className = "video-row";
  row.innerHTML = `
    <div class="video-row-grid">
      <label><span class="field-label">Title</span><input data-f="title" value="${escapeHTML(g.title)}" /></label>
      <label><span class="field-label">Belt</span>
        <select data-f="belt"><option value="all" ${g.belt === "all" ? "selected" : ""}>all</option>${BELTS.map((b) => `<option value="${b}" ${b === g.belt ? "selected" : ""}>${b}</option>`).join("")}</select>
      </label>
      <label class="field-checkbox"><input type="checkbox" data-f="premium" ${g.premium ? "checked" : ""} /> Premium</label>
      <label style="grid-column:1/-1;"><span class="field-label">Description</span><input data-f="description" value="${escapeHTML(g.description || "")}" /></label>
      <label style="grid-column:1/-1;"><span class="field-label">File URL</span><input data-f="fileUrl" value="${escapeHTML(g.fileUrl || "")}" /></label>
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
      description: get("description").value,
      fileUrl: get("fileUrl").value,
      premium: get("premium").checked,
    };
    try {
      await api(`/admin/guides/${g.id}`, { method: "PUT", body: JSON.stringify(payload) });
      status.style.color = "var(--green-belt)";
      status.textContent = "Saved";
      setTimeout(() => (status.textContent = ""), 2000);
    } catch (err) {
      status.style.color = "var(--red)";
      status.textContent = err.message;
    }
  });

  row.querySelector('[data-action="delete"]').addEventListener("click", async () => {
    if (!confirm(`Delete "${g.title}"? This can't be undone.`)) return;
    try {
      await api(`/admin/guides/${g.id}`, { method: "DELETE" });
      row.remove();
    } catch (err) {
      alert(err.message);
    }
  });

  return row;
}

async function handleAddGuide(e) {
  e.preventDefault();
  const val = (id) => document.getElementById(id).value;
  const payload = {
    title: val("ng-title"),
    belt: val("ng-belt"),
    description: val("ng-description"),
    fileUrl: val("ng-url"),
    premium: document.getElementById("ng-premium").checked,
  };
  try {
    await api("/admin/guides", { method: "POST", body: JSON.stringify(payload) });
    e.target.reset();
    document.getElementById("ng-premium").checked = true;
    loadGuides();
  } catch (err) {
    alert(err.message);
  }
}

/* ---------------- ANNOUNCEMENTS ---------------- */

async function loadAnnouncements() {
  const list = document.getElementById("announcement-list");
  list.innerHTML = `<p class="loading-note">Loading…</p>`;
  try {
    const items = await api("/announcements");
    list.innerHTML = "";
    items.forEach((a) => {
      const row = document.createElement("div");
      row.className = "content-row";
      row.innerHTML = `
        <span class="content-row-key">${a.pinned ? "📌 " : ""}${escapeHTML(a.title)}</span>
        <p style="color:var(--muted);font-size:13px;margin:0;">${escapeHTML(a.body)}</p>
        <div class="row-actions"><button class="btn btn-danger" type="button">Delete</button></div>
      `;
      row.querySelector("button").addEventListener("click", async () => {
        if (!confirm(`Delete "${a.title}"?`)) return;
        try {
          await api(`/admin/announcements/${a.id}`, { method: "DELETE" });
          row.remove();
        } catch (err) {
          alert(err.message);
        }
      });
      list.appendChild(row);
    });
    if (items.length === 0) list.innerHTML = `<p class="loading-note">No announcements yet.</p>`;
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

async function handleAddAnnouncement(e) {
  e.preventDefault();
  const val = (id) => document.getElementById(id).value;
  const payload = { title: val("na-title"), body: val("na-body"), pinned: document.getElementById("na-pinned").checked };
  try {
    await api("/admin/announcements", { method: "POST", body: JSON.stringify(payload) });
    e.target.reset();
    loadAnnouncements();
  } catch (err) {
    alert(err.message);
  }
}

/* ---------------- LIVE SESSIONS ---------------- */

async function loadLiveSessions() {
  const list = document.getElementById("live-session-list");
  list.innerHTML = `<p class="loading-note">Loading…</p>`;
  try {
    const items = await api("/live-sessions");
    list.innerHTML = "";
    items.forEach((s) => {
      const row = document.createElement("div");
      row.className = "content-row";
      const when = new Date(s.sessionAt).toLocaleString();
      row.innerHTML = `
        <span class="content-row-key">${escapeHTML(s.title)} — ${escapeHTML(when)}</span>
        <p style="color:var(--muted);font-size:13px;margin:0;">${escapeHTML(s.instructor)} · ${s.durationMinutes} min · ${escapeHTML(s.belt)}</p>
        <div class="row-actions"><button class="btn btn-danger" type="button">Delete</button></div>
      `;
      row.querySelector("button").addEventListener("click", async () => {
        if (!confirm(`Delete "${s.title}"?`)) return;
        try {
          await api(`/admin/live-sessions/${s.id}`, { method: "DELETE" });
          row.remove();
        } catch (err) {
          alert(err.message);
        }
      });
      list.appendChild(row);
    });
    if (items.length === 0) list.innerHTML = `<p class="loading-note">No live sessions scheduled.</p>`;
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

async function handleAddLiveSession(e) {
  e.preventDefault();
  const val = (id) => document.getElementById(id).value;
  const localDatetime = val("nl-datetime"); // "YYYY-MM-DDTHH:MM" in the browser's local time
  const payload = {
    title: val("nl-title"),
    sessionAt: localDatetime ? localDatetime.replace("T", " ") + ":00" : "",
    durationMinutes: parseInt(val("nl-duration"), 10) || 60,
    instructor: val("nl-instructor"),
    belt: val("nl-belt"),
    description: val("nl-description"),
    joinUrl: val("nl-url"),
  };
  try {
    await api("/admin/live-sessions", { method: "POST", body: JSON.stringify(payload) });
    e.target.reset();
    loadLiveSessions();
  } catch (err) {
    alert(err.message);
  }
}

/* ---------------- RESOURCE CARDS ---------------- */

const RESOURCE_CATEGORIES = ["terminology", "philosophy", "grading", "instructor"];

async function loadResources() {
  const list = document.getElementById("resource-list");
  list.innerHTML = `<p class="loading-note">Loading…</p>`;
  try {
    const grouped = await api("/resources");
    list.innerHTML = "";
    let count = 0;
    RESOURCE_CATEGORIES.forEach((cat) => {
      (grouped[cat] || []).forEach((r) => {
        count++;
        list.appendChild(renderResourceRow({ ...r, category: cat }));
      });
    });
    if (count === 0) list.innerHTML = `<p class="loading-note">No resource cards yet.</p>`;
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

function renderResourceRow(r) {
  const row = document.createElement("div");
  row.className = "video-row";
  row.innerHTML = `
    <div class="video-row-grid">
      <label><span class="field-label">Category</span>
        <select data-f="category">${RESOURCE_CATEGORIES.map((c) => `<option value="${c}" ${c === r.category ? "selected" : ""}>${c}</option>`).join("")}</select>
      </label>
      <label class="field-checkbox"><input type="checkbox" data-f="premium" ${r.premium ? "checked" : ""} /> Members only</label>
      <label style="grid-column:1/-1;"><span class="field-label">Title</span><input data-f="title" value="${escapeHTML(r.title)}" /></label>
      <label style="grid-column:1/-1;"><span class="field-label">Body</span><input data-f="body" value="${escapeHTML(r.body || "")}" /></label>
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
      category: get("category").value,
      title: get("title").value,
      body: get("body").value,
      premium: get("premium").checked,
    };
    try {
      await api(`/admin/resources/${r.id}`, { method: "PUT", body: JSON.stringify(payload) });
      status.style.color = "var(--green-belt)";
      status.textContent = "Saved";
      setTimeout(() => (status.textContent = ""), 2000);
    } catch (err) {
      status.style.color = "var(--red)";
      status.textContent = err.message;
    }
  });

  row.querySelector('[data-action="delete"]').addEventListener("click", async () => {
    if (!confirm(`Delete "${r.title}"? This can't be undone.`)) return;
    try {
      await api(`/admin/resources/${r.id}`, { method: "DELETE" });
      row.remove();
    } catch (err) {
      alert(err.message);
    }
  });

  return row;
}

async function handleAddResource(e) {
  e.preventDefault();
  const val = (id) => document.getElementById(id).value;
  const payload = {
    category: val("nr-category"),
    title: val("nr-title"),
    body: val("nr-body"),
    premium: document.getElementById("nr-premium").checked,
  };
  try {
    await api("/admin/resources", { method: "POST", body: JSON.stringify(payload) });
    e.target.reset();
    loadResources();
  } catch (err) {
    alert(err.message);
  }
}

/* ---------------- SUPER ADMIN: ACCOUNTS & STAFF ---------------- */

async function handleAddAccount(e) {
  e.preventDefault();
  const errEl = document.getElementById("add-account-error");
  errEl.classList.add("hidden");
  const payload = {
    name: document.getElementById("nacc-name").value,
    email: document.getElementById("nacc-email").value,
    password: document.getElementById("nacc-password").value,
    role: document.getElementById("nacc-role").value,
  };
  try {
    await api("/admin/accounts", { method: "POST", body: JSON.stringify(payload) });
    e.target.reset();
    loadStaffDirectory();
  } catch (err) {
    errEl.textContent = err.message;
    errEl.classList.remove("hidden");
  }
}

async function loadStaffDirectory() {
  const list = document.getElementById("staff-list");
  list.innerHTML = `<p class="loading-note">Loading…</p>`;
  try {
    const staff = await api("/admin/users?role=admin");
    list.innerHTML = "";
    staff.forEach((u) => list.appendChild(renderStaffRow(u)));
    if (staff.length === 0) list.innerHTML = `<p class="loading-note">No other admin accounts yet.</p>`;
  } catch (err) {
    list.innerHTML = `<p class="error-note">${escapeHTML(err.message)}</p>`;
  }
}

function renderStaffRow(u) {
  const row = document.createElement("div");
  row.className = "content-row";
  const isSuper = u.role === "super_admin";
  row.innerHTML = `
    <span class="content-row-key">${escapeHTML(u.name)} · ${escapeHTML(u.email)} · ${escapeHTML(u.role.replace("_", " "))}</span>
    <div class="row-actions">
      ${isSuper
        ? `<span style="font-size:12px;color:var(--muted);">Change directly in the database only</span>`
        : `<button class="btn btn-danger" type="button">Revoke admin access</button>`}
      <span class="save-status" style="font-size:12px;color:var(--green-belt);"></span>
    </div>
  `;
  if (!isSuper) {
    row.querySelector("button").addEventListener("click", async () => {
      if (!confirm(`Revoke admin access for ${u.name}? They'll become a regular user account.`)) return;
      try {
        await api(`/admin/users/${u.id}/role`, { method: "PUT", body: JSON.stringify({ role: "user" }) });
        row.remove();
      } catch (err) {
        alert(err.message);
      }
    });
  }
  return row;
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
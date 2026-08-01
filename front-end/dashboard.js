/* ---------------- CONFIG (mirrors app.js) ---------------- */

const API_BASE = window.location.hostname === "localhost"
  ? "http://localhost:8000/api"
  : "https://fullstack-backend-7kas.onrender.com/api";

const TOKEN_KEY = "kk_token";

const BELTS = [
  { id: "white", name: "White", hex: "#F2EFE7", text: "#1A1A1A", outline: true },
  { id: "yellow", name: "Yellow", hex: "#E8C339", text: "#1A1A1A" },
  { id: "orange", name: "Orange", hex: "#E07A3E", text: "#1A1A1A" },
  { id: "green", name: "Green", hex: "#3E8E5C", text: "#F2EFE7" },
  { id: "blue", name: "Blue", hex: "#3B6FA6", text: "#F2EFE7" },
  { id: "purple", name: "Purple", hex: "#7A5CA6", text: "#F2EFE7" },
  { id: "brown", name: "Brown", hex: "#6E4A2E", text: "#F2EFE7" },
  { id: "black", name: "Black", hex: "#1A1A1A", text: "#F2EFE7" },
];
const TYPES = ["Kihon", "Kata", "Kumite", "Bunkai", "Conditioning"];

function beltById(id) { return BELTS.find((b) => b.id === id) || BELTS[0]; }

const state = {
  user: null,
  progress: null,
  beltOrder: [],
  videos: [],
  completedVideoIds: new Set(),
  guides: [],
  resources: {},
  beltFilter: null, // set once we know the user's belt
  typeFilter: "all",
};

/* ---------------- API HELPERS ---------------- */

function getToken() { return sessionStorage.getItem(TOKEN_KEY); }
function clearToken() { sessionStorage.removeItem(TOKEN_KEY); }

async function api(path, options = {}) {
  const token = getToken();
  const headers = { "Content-Type": "application/json", ...(options.headers || {}) };
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(`${API_BASE}${path}`, { ...options, headers });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || "Something went wrong. Please try again.");
  return data;
}

function escapeHTML(str) {
  const div = document.createElement("div");
  div.textContent = str == null ? "" : String(str);
  return div.innerHTML;
}

/* ---------------- INIT ---------------- */

async function init() {
  if (!getToken()) {
    showSignedOutGate();
    return;
  }

  handleCheckoutRedirect();

  try {
    const dash = await api("/dashboard");
    state.user = dash.user;
    state.progress = dash.progress;
    state.beltOrder = dash.beltOrder;
    state.beltFilter = dash.progress.currentBelt;
  } catch (err) {
    console.error(err);
    clearToken();
    showSignedOutGate();
    return;
  }

  document.getElementById("dash-root").style.display = "";
  wireNav();
  wireQuickNav();
  renderHero();

  const [videos, completed, guides, announcements, live, resources] = await Promise.all([
    api("/videos").catch(() => []),
    api("/progress/videos").catch(() => []),
    api("/guides").catch(() => []),
    api("/announcements").catch(() => []),
    api("/live-sessions").catch(() => []),
    api("/resources").catch(() => ({})),
  ]);

  state.videos = videos;
  state.completedVideoIds = new Set(completed);
  state.guides = guides;
  state.resources = resources;

  renderAnnouncements(announcements);
  renderLiveSessions(live);
  renderBeltFilters();
  renderTypeFilters();
  renderVideoGrid();
  renderGuides();
  renderResourceGroup("grading-grid", resources.grading || []);
  renderResourceGroup("terminology-grid", resources.terminology || [], true);
  renderResourceGroup("philosophy-grid", resources.philosophy || []);
  renderResourceGroup("instructor-grid", resources.instructor || []);
  renderAccountSection();
}

function showSignedOutGate() {
  document.getElementById("signed-out-gate").style.display = "";
  document.getElementById("dash-root").style.display = "none";
}

function handleCheckoutRedirect() {
  const params = new URLSearchParams(window.location.search);
  const status = params.get("checkout");
  if (!status) return;
  window.history.replaceState({}, "", window.location.pathname);
  if (status === "success") {
    showToast("Payment received — welcome to your new membership.");
  } else if (status === "cancelled") {
    showToast("Checkout was cancelled. No charge was made.");
  }
}

function showToast(message) {
  const el = document.createElement("div");
  el.className = "toast";
  el.textContent = message;
  document.body.appendChild(el);
  setTimeout(() => el.classList.add("show"), 10);
  setTimeout(() => { el.classList.remove("show"); setTimeout(() => el.remove(), 300); }, 5000);
}

/* ---------------- HERO / PROGRESS ---------------- */

function renderHero() {
  const user = state.user;
  const progress = state.progress;
  const belt = beltById(progress.currentBelt);

  document.getElementById("dash-hero-belt-bg").style.setProperty("--belt-color", belt.hex);
  document.getElementById("dash-user-name").textContent = user.name;
  document.getElementById("dash-belt-swatch").style.background = belt.hex;
  document.getElementById("dash-belt-label").textContent = belt.name + " Belt";

  const tierLabel = user.subscriptionTier
    ? (user.subscriptionTier === "trial" ? "Free Trial" : user.subscriptionTier[0].toUpperCase() + user.subscriptionTier.slice(1) + " Member")
    : "No active plan";
  const memberSince = user.memberSince ? new Date(user.memberSince) : null;
  document.getElementById("dash-member-since").textContent = tierLabel +
    (memberSince ? ` · member since ${memberSince.toLocaleDateString(undefined, { month: "short", year: "numeric" })}` : "");

  document.getElementById("dash-next-grading").textContent = progress.nextGradingDate
    ? new Date(progress.nextGradingDate + "T00:00:00").toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })
    : "Not yet scheduled";

  const targetBelt = progress.targetBelt ? beltById(progress.targetBelt).name + " Belt" : "—";
  document.getElementById("dash-target-belt").textContent = targetBelt;

  document.getElementById("dash-lesson-count").textContent =
    `${progress.lessonsCompletedForBelt} / ${progress.lessonsTotalForBelt}`;

  const pct = progress.lessonsTotalForBelt > 0
    ? Math.round((progress.lessonsCompletedForBelt / progress.lessonsTotalForBelt) * 100)
    : 0;
  document.getElementById("dash-bar-fill").style.width = pct + "%";

  renderPathway();
}

function renderPathway() {
  const el = document.getElementById("dash-pathway");
  el.innerHTML = "";
  const { currentBelt, targetBelt } = state.progress;
  const currentIndex = state.beltOrder.indexOf(currentBelt);

  state.beltOrder.forEach((slug, i) => {
    const belt = beltById(slug);
    const node = document.createElement("div");
    let cls = "dash-belt-node";
    if (i < currentIndex) cls += " is-complete";
    if (slug === currentBelt) cls += " is-current";
    if (targetBelt && slug === targetBelt) cls += " is-target";
    node.className = cls;
    node.innerHTML = `
      <span class="dash-belt-dot" style="background:${i <= currentIndex ? belt.hex : "var(--ink)"};${belt.outline ? "border-color:#55524A;" : ""}"></span>
      <span class="dash-belt-node-label">${escapeHTML(belt.name)}</span>
    `;
    el.appendChild(node);
  });
}

/* ---------------- ANNOUNCEMENTS ---------------- */

function renderAnnouncements(items) {
  const el = document.getElementById("announcements-list");
  if (!items.length) {
    el.innerHTML = `<p class="empty-note-inline">No announcements right now — check back soon.</p>`;
    return;
  }
  el.innerHTML = items.map((a) => `
    <article class="announcement-item ${a.pinned ? "is-pinned" : ""}">
      <div class="announcement-top">
        ${a.pinned ? `<span class="announcement-pin">Pinned</span>` : ""}
        <h3 class="announcement-title">${escapeHTML(a.title)}</h3>
      </div>
      <p class="announcement-body">${escapeHTML(a.body)}</p>
      <p class="announcement-date">${new Date(a.createdAt).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })}</p>
    </article>
  `).join("");
}

/* ---------------- LIVE TRAINING ---------------- */

function renderLiveSessions(items) {
  const el = document.getElementById("live-list");
  if (!items.length) {
    el.innerHTML = `<p class="empty-note-inline">No live sessions scheduled at the moment.</p>`;
    return;
  }
  el.innerHTML = items.map((s) => {
    const d = new Date(s.sessionAt);
    const belt = s.belt && s.belt !== "all" ? beltById(s.belt) : null;
    return `
      <article class="live-card">
        <div class="live-date-block">
          <span class="live-date-month">${d.toLocaleDateString(undefined, { month: "short" })}</span>
          <span class="live-date-day">${d.getDate()}</span>
        </div>
        <div class="live-body">
          <h3 class="live-title">${escapeHTML(s.title)}</h3>
          <p class="live-meta">${d.toLocaleDateString(undefined, { weekday: "short", hour: "numeric", minute: "2-digit" })} · ${s.durationMinutes} min · ${escapeHTML(s.instructor)}${belt ? ` · ${escapeHTML(belt.name)} focus` : ""}</p>
          ${s.description ? `<p class="live-desc">${escapeHTML(s.description)}</p>` : ""}
        </div>
        ${s.joinUrl ? `<a class="btn btn-outline" href="${escapeHTML(s.joinUrl)}" target="_blank" rel="noopener noreferrer">Join</a>` : `<span class="btn btn-outline locked-btn">Link coming soon</span>`}
      </article>
    `;
  }).join("");
}

/* ---------------- LESSON LIBRARY ---------------- */

function makePill(label, active, onClick) {
  const btn = document.createElement("button");
  btn.className = "pill" + (active ? " active" : "");
  btn.textContent = label;
  btn.addEventListener("click", onClick);
  return btn;
}

function renderBeltFilters() {
  const el = document.getElementById("dash-belt-filters");
  el.innerHTML = "";
  el.appendChild(makePill("All ranks", state.beltFilter === "all", () => setBeltFilter("all")));
  BELTS.forEach((b) => {
    const pill = makePill(b.name, state.beltFilter === b.id, () => setBeltFilter(b.id));
    const dot = document.createElement("span");
    dot.className = "pill-dot";
    dot.style.background = b.hex;
    if (b.outline) dot.style.border = "1px solid #55524A";
    pill.prepend(dot);
    if (b.id === state.progress.currentBelt) {
      const tag = document.createElement("span");
      tag.textContent = " (you)";
      tag.style.opacity = "0.6";
      pill.appendChild(tag);
    }
    el.appendChild(pill);
  });
}

function renderTypeFilters() {
  const el = document.getElementById("dash-type-filters");
  el.innerHTML = "";
  el.appendChild(makePill("All types", state.typeFilter === "all", () => setTypeFilter("all")));
  TYPES.forEach((t) => el.appendChild(makePill(t, state.typeFilter === t, () => setTypeFilter(t))));
}

function setBeltFilter(id) { state.beltFilter = id; renderBeltFilters(); renderVideoGrid(); }
function setTypeFilter(t) { state.typeFilter = t; renderTypeFilters(); renderVideoGrid(); }

function isLocked(video) {
  return video.premium && !state.user.subscriptionActive;
}

function renderVideoGrid() {
  const grid = document.getElementById("dash-video-grid");
  const emptyNote = document.getElementById("dash-empty-note");
  grid.innerHTML = "";

  // Only show videos this student's tier actually grants -- a trial/no-plan
  // student doesn't need a wall of locked premium cards cluttering their page.
  const accessible = state.videos.filter((v) => !v.premium || state.user.subscriptionActive);

  const filtered = accessible.filter(
    (v) => (state.beltFilter === "all" || v.belt === state.beltFilter) &&
           (state.typeFilter === "all" || v.type === state.typeFilter)
  );
  emptyNote.classList.toggle("hidden", filtered.length > 0);

  filtered.forEach((v) => {
    const belt = beltById(v.belt);
    const locked = isLocked(v);
    const done = state.completedVideoIds.has(v.id);

    const card = document.createElement("div");
    card.className = "video-card";
    card.tabIndex = 0;
    card.setAttribute("role", "button");
    card.addEventListener("click", () => openVideoModal(v));
    card.addEventListener("keydown", (e) => { if (e.key === "Enter") openVideoModal(v); });

    card.innerHTML = `
      <div class="video-thumb" style="background: linear-gradient(135deg, ${belt.hex} 0%, #121212 130%);">
        <div class="video-play-circle">${locked ? lockIconHTML() : playIconHTML()}</div>
        <span class="video-duration">${escapeHTML(v.duration)}</span>
        <span class="video-lesson-tag" style="background:${belt.hex};color:${belt.text};">${belt.name} · Lesson ${String(v.lesson).padStart(2, "0")}</span>
        ${done ? `<span class="video-duration" style="left:10px;right:auto;background:rgba(62,142,92,0.85);">✓ Done</span>` : ""}
      </div>
      <div class="video-body">
        <span class="video-type">${escapeHTML(v.type)}</span>
        <h3 class="video-title">${escapeHTML(v.title)}</h3>
        <p class="video-instructor">${escapeHTML(v.instructor)}</p>
      </div>
    `;
    grid.appendChild(card);
  });

  applyCollapsible(grid, 3);
}

function playIconHTML() {
  return `<span style="width:0;height:0;border-top:7px solid transparent;border-bottom:7px solid transparent;border-left:11px solid #EDEAE2;display:inline-block;margin-left:2px;"></span>`;
}
function lockIconHTML() {
  return `<span class="lock-wrap"><span class="icon-lock"></span><span class="icon-lock-body"></span></span>`;
}

/**
 * Shows only the first `visibleCount` children of a grid container and adds
 * a "Show N more" toggle for the rest, so long lists (videos, guides,
 * resource cards) don't dump everything on the page at once. Call this
 * AFTER the container's cards have been rendered/appended.
 */
function applyCollapsible(container, visibleCount = 3) {
  if (!container) return;
  const oldToggle = document.getElementById(container.id + "-toggle");
  if (oldToggle) oldToggle.remove();

  const cards = Array.from(container.children);
  cards.forEach((card) => card.classList.remove("collapsible-hidden"));
  if (cards.length <= visibleCount) return;

  cards.forEach((card, i) => {
    if (i >= visibleCount) card.classList.add("collapsible-hidden");
  });

  const hiddenCount = cards.length - visibleCount;
  const toggleWrap = document.createElement("div");
  toggleWrap.className = "collapsible-toggle-wrap";
  toggleWrap.id = container.id + "-toggle";
  const btn = document.createElement("button");
  btn.className = "btn btn-outline";
  btn.type = "button";
  btn.textContent = `Show ${hiddenCount} more`;
  let expanded = false;
  btn.addEventListener("click", () => {
    expanded = !expanded;
    cards.forEach((card, i) => {
      if (i >= visibleCount) card.classList.toggle("collapsible-hidden", !expanded);
    });
    btn.textContent = expanded ? "Show less" : `Show ${hiddenCount} more`;
    if (!expanded) container.scrollIntoView({ behavior: "smooth", block: "nearest" });
  });
  toggleWrap.appendChild(btn);
  container.insertAdjacentElement("afterend", toggleWrap);
}

/* --- Video modal (mirrors app.js's, plus a mark-complete toggle) --- */

const modalRoot = document.getElementById("modal-root");
function closeModal() { modalRoot.innerHTML = ""; }

function openOverlay(bodyHTML, onMount) {
  modalRoot.innerHTML = `
    <div class="modal-overlay" id="overlay">
      <div class="modal-box" id="modal-box">
        <button class="modal-close" id="modal-close-btn" aria-label="Close">×</button>
        ${bodyHTML}
      </div>
    </div>
  `;
  document.getElementById("overlay").addEventListener("click", (e) => { if (e.target.id === "overlay") closeModal(); });
  document.getElementById("modal-close-btn").addEventListener("click", closeModal);
  if (onMount) onMount();
}

function embedForUrl(url) {
  try {
    const u = new URL(url);
    let embedSrc = null;
    if (u.hostname.includes("youtube.com") && u.searchParams.get("v")) {
      embedSrc = `https://www.youtube.com/embed/${u.searchParams.get("v")}`;
    } else if (u.hostname === "youtu.be") {
      embedSrc = `https://www.youtube.com/embed/${u.pathname.slice(1)}`;
    } else if (u.hostname.includes("vimeo.com")) {
      const id = u.pathname.split("/").filter(Boolean).pop();
      embedSrc = `https://player.vimeo.com/video/${id}`;
    }
    if (embedSrc) {
      return `<iframe src="${embedSrc}" style="width:100%;height:260px;border:0;display:block;" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>`;
    }
    return `<div style="padding:24px;text-align:center;"><a href="${escapeHTML(url)}" target="_blank" rel="noopener noreferrer" class="btn btn-outline">Open video</a></div>`;
  } catch {
    return `<div style="padding:24px;text-align:center;"><a href="${escapeHTML(url)}" target="_blank" rel="noopener noreferrer" class="btn btn-outline">Open video</a></div>`;
  }
}

function openVideoModal(video) {
  const belt = beltById(video.belt);

  if (isLocked(video)) {
    openOverlay(`
      <div class="modal-body" style="text-align:center;padding:50px 32px;">
        <div class="lock-wrap" style="margin-bottom:16px;">${lockIconHTML()}</div>
        <h3 class="disp" style="font-size:28px;margin:0 0 10px;">MEMBERS ONLY</h3>
        <p style="color:var(--muted);margin-bottom:26px;max-width:380px;margin-left:auto;margin-right:auto;">
          "${escapeHTML(video.title)}" is part of the full library. Upgrade your membership to unlock every rank.
        </p>
        <a class="btn btn-primary" href="index.html#membership">View membership</a>
      </div>
    `);
    return;
  }

  const playerHTML = video.videoUrl
    ? `<div class="video-embed">${embedForUrl(video.videoUrl)}</div>`
    : `<div class="video-thumb" style="height:260px;background:linear-gradient(135deg, ${belt.hex} 0%, #121212 140%);">
         <div class="video-play-circle" style="width:68px;height:68px;">${playIconHTML()}</div>
       </div>`;

  const done = state.completedVideoIds.has(video.id);

  openOverlay(`
    <div>
      ${playerHTML}
      <div class="modal-body">
        <span class="video-type">${escapeHTML(video.type)} · ${belt.name} belt · Lesson ${String(video.lesson).padStart(2, "0")}</span>
        <h3 style="font-size:22px;margin:8px 0 6px;">${escapeHTML(video.title)}</h3>
        <p style="color:var(--muted);font-size:14px;margin-bottom:10px;">${escapeHTML(video.instructor)} · ${escapeHTML(video.duration)}</p>
        ${video.caption ? `<p style="font-size:14px;color:#D4D1C6;margin-bottom:18px;">${escapeHTML(video.caption)}</p>` : ""}
        <button class="btn ${done ? "btn-outline" : "btn-primary"}" id="mark-complete-btn">${done ? "✓ Marked complete" : "Mark as complete"}</button>
      </div>
    </div>
  `, () => {
    document.getElementById("mark-complete-btn").addEventListener("click", async () => {
      const nowDone = state.completedVideoIds.has(video.id);
      try {
        await api(`/progress/videos/${video.id}`, { method: nowDone ? "DELETE" : "POST" });
        if (nowDone) state.completedVideoIds.delete(video.id); else state.completedVideoIds.add(video.id);
        closeModal();
        openVideoModal(video);
        renderVideoGrid();
        await refreshProgress();
      } catch (err) {
        alert(err.message);
      }
    });
  });
}

async function refreshProgress() {
  try {
    const dash = await api("/dashboard");
    state.progress = dash.progress;
    document.getElementById("dash-next-grading").textContent = state.progress.nextGradingDate
      ? new Date(state.progress.nextGradingDate + "T00:00:00").toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })
      : "Not yet scheduled";
    document.getElementById("dash-lesson-count").textContent =
      `${state.progress.lessonsCompletedForBelt} / ${state.progress.lessonsTotalForBelt}`;
    const pct = state.progress.lessonsTotalForBelt > 0
      ? Math.round((state.progress.lessonsCompletedForBelt / state.progress.lessonsTotalForBelt) * 100)
      : 0;
    document.getElementById("dash-bar-fill").style.width = pct + "%";
  } catch { /* non-critical */ }
}

/* ---------------- TRAINING GUIDES ---------------- */

function renderGuides() {
  const el = document.getElementById("guide-grid");
  if (!state.guides.length) {
    el.innerHTML = `<p class="empty-note-inline">No guides published yet.</p>`;
    return;
  }
  el.innerHTML = state.guides.map((g) => {
    const belt = g.belt && g.belt !== "all" ? beltById(g.belt) : null;
    return `
      <article class="guide-card">
        <div class="guide-icon"><span class="guide-icon-label">PDF</span></div>
        <h3 class="guide-title">${escapeHTML(g.title)}</h3>
        <p class="guide-desc">${escapeHTML(g.description)}</p>
        <span class="guide-belt-tag">${belt ? escapeHTML(belt.name) + " belt" : "All ranks"}</span>
        ${g.locked
          ? `<a class="btn btn-outline locked-btn" href="index.html#membership">Members only</a>`
          : (g.fileUrl ? `<a class="btn btn-outline" href="${escapeHTML(g.fileUrl)}" target="_blank" rel="noopener noreferrer">Download</a>` : `<span class="btn btn-outline locked-btn">Coming soon</span>`)}
      </article>
    `;
  }).join("");
  applyCollapsible(el, 3);
}

/* ---------------- RESOURCE SECTIONS ---------------- */

function renderResourceGroup(elementId, items, compact) {
  const el = document.getElementById(elementId);
  if (!el) return;
  if (!items.length) {
    el.innerHTML = `<p class="empty-note-inline">Nothing here yet.</p>`;
    return;
  }
  el.innerHTML = items.map((r) => `
    <article class="resource-card ${r.locked ? "is-locked" : ""}">
      <h3 class="resource-card-title">${escapeHTML(r.title)}</h3>
      ${r.locked
        ? `<p class="resource-lock-note">${lockIconHTML()} Members only</p>`
        : `<p class="resource-card-body">${escapeHTML(r.body)}</p>`}
    </article>
  `).join("");
  applyCollapsible(el, compact ? 6 : 3);
}

/* ---------------- NAV ---------------- */

function wireNav() {
  document.getElementById("nav-home").addEventListener("click", () => { window.location.href = "index.html"; });
  document.getElementById("nav-signout").addEventListener("click", async () => {
    try { await api("/auth/logout", { method: "POST" }); } catch { /* fine */ }
    clearToken();
    window.location.href = "index.html";
  });

  const badge = document.getElementById("tier-badge");
  if (state.user.subscriptionTier) {
    badge.textContent = state.user.subscriptionActive ? "Active member" : "Membership inactive";
    badge.classList.remove("hidden");
  }

  const menuToggle = document.getElementById("menu-toggle");
  const navLinks = document.getElementById("nav-links");
  function setMenuOpen(open) {
    navLinks.classList.toggle("open", open);
    menuToggle.classList.toggle("active", open);
    menuToggle.setAttribute("aria-expanded", String(open));
  }
  menuToggle.addEventListener("click", () => setMenuOpen(!navLinks.classList.contains("open")));
  document.addEventListener("click", (e) => {
    if (navLinks.classList.contains("open") && !navLinks.contains(e.target) && !menuToggle.contains(e.target)) setMenuOpen(false);
  });
}

function wireQuickNav() {
  document.querySelectorAll(".dash-quicknav [data-jump]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const target = document.getElementById(btn.dataset.jump);
      if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });
}

/* ---------------- ACCOUNT ---------------- */

const TIER_NAMES = { trial: "Free Trial", standard: "Academy Member", advanced: "Advanced Member" };

function renderAccountSection() {
  const user = state.user;
  const infoEl = document.getElementById("account-info");
  const actionsEl = document.getElementById("account-actions");

  const rows = [
    ["Name", user.name],
    ["Email", user.email],
    ["Email verified", user.emailVerified ? "Yes" : "No"],
    ["Membership", user.subscriptionTier ? (TIER_NAMES[user.subscriptionTier] || user.subscriptionTier) : "None yet"],
  ];
  if (user.subscriptionTier === "trial" && user.trialEndsAt) {
    rows.push(["Trial ends", new Date(user.trialEndsAt).toLocaleDateString()]);
  }
  if (user.subscriptionTier && user.subscriptionTier !== "trial") {
    rows.push(["Account status", user.accountStatus === "paused" ? "On hold" : "Active"]);
  }

  infoEl.innerHTML = rows.map(([label, value]) => `
    <div class="info-row">
      <span class="info-row-label">${escapeHTML(label)}</span>
      <span class="info-row-value">${escapeHTML(String(value))}</span>
    </div>
  `).join("");

  const buttons = [];
  const isPaid = user.subscriptionTier === "standard" || user.subscriptionTier === "advanced";

  if (user.hasBilling) {
    buttons.push(`<button class="btn btn-outline" id="acct-manage-billing">Manage billing</button>`);
  }
  if (isPaid) {
    buttons.push(`<button class="btn btn-outline" id="acct-hold-toggle">${user.accountStatus === "paused" ? "Resume account" : "Put account on hold"}</button>`);
  }
  buttons.push(`<button class="btn btn-danger" id="acct-delete">Delete account</button>`);
  actionsEl.innerHTML = buttons.join("");

  const manageBtn = document.getElementById("acct-manage-billing");
  if (manageBtn) manageBtn.addEventListener("click", handleManageBilling);

  const holdBtn = document.getElementById("acct-hold-toggle");
  if (holdBtn) holdBtn.addEventListener("click", handleHoldToggle);

  document.getElementById("acct-delete").addEventListener("click", handleDeleteAccount);
}

async function handleManageBilling() {
  try {
    const data = await api("/account/billing-portal", { method: "POST" });
    if (data.url) window.location.href = data.url;
  } catch (err) {
    alert(err.message);
  }
}

async function handleHoldToggle() {
  const isPaused = state.user.accountStatus === "paused";
  const verb = isPaused ? "resume" : "pause";
  if (!confirm(isPaused ? "Resume billing and reactivate your membership?" : "Put your membership on hold? You'll lose access to premium content until you resume.")) {
    return;
  }
  try {
    await api(`/account/${verb}`, { method: "POST" });
    const dash = await api("/dashboard");
    state.user = dash.user;
    renderAccountSection();
  } catch (err) {
    alert(err.message);
  }
}

function handleDeleteAccount() {
  openOverlay(`
    <div class="modal-body">
      <h3 class="disp" style="font-size:24px;margin:0 0 12px;">DELETE YOUR ACCOUNT?</h3>
      <p style="color:var(--muted);font-size:14px;margin-bottom:22px;">
        This cancels any active subscription and permanently deletes your progress, belt record, and login.
        This can't be undone.
      </p>
      <button class="btn btn-danger btn-block" id="confirm-delete">Yes, delete my account</button>
    </div>
  `, () => {
    document.getElementById("confirm-delete").addEventListener("click", async () => {
      try {
        await api("/account", { method: "DELETE" });
        clearToken();
        window.location.href = "index.html";
      } catch (err) {
        alert(err.message);
      }
    });
  });
}

document.addEventListener("DOMContentLoaded", init);
/* ---------------- CONFIG ---------------- */

const API_BASE = window.location.hostname === "localhost"
  ? "http://localhost:8000/api"
  : "https://fullstack-backend-7kas.onrender.com/api";

const TOKEN_KEY = "kk_token";

// Belt colors/names are presentation-only, so they stay on the frontend.
// The backend only ever refers to a belt by its slug.
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

/* ---------------- STATE ---------------- */

const state = {
  videos: [],
  tiers: [],
  currentUser: null,
  beltFilter: "all",
  typeFilter: "all",
  authMode: null,       // null | "signin" | "signup"
  authError: "",
  authBusy: false,
  pendingTier: null,     // tier the user tried to buy before being signed in
  checkoutTier: null,
  checkoutStep: "form",  // form | processing | success
};

/* ---------------- API HELPERS ---------------- */

function getToken() { return localStorage.getItem(TOKEN_KEY); }
function setToken(token) { localStorage.setItem(TOKEN_KEY, token); }
function clearToken() { localStorage.removeItem(TOKEN_KEY); }

async function api(path, options = {}) {
  const token = getToken();
  const headers = { "Content-Type": "application/json", ...(options.headers || {}) };
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(`${API_BASE}${path}`, { ...options, headers });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.error || "Something went wrong. Please try again.");
  }
  return data;
}

/* ---------------- INIT / DATA LOAD ---------------- */

async function init() {
  renderBeltStrip();
  renderFilters();
  wireNav();

  const grid = document.getElementById("video-grid");
  grid.innerHTML = `<p class="loading-note">Loading the library…</p>`;

  try {
    const [videos, tiers] = await Promise.all([
      api("/videos"),
      api("/tiers"),
    ]);
    state.videos = videos;
    state.tiers = tiers;
  } catch (err) {
    grid.innerHTML = `<p class="loading-note">Couldn't reach the server. Is the backend running?</p>`;
    console.error(err);
    return;
  }

  renderVideoGrid();
  renderTierGrid();

  if (getToken()) {
    try {
      const { user } = await api("/auth/me");
      state.currentUser = user;
    } catch {
      clearToken(); // expired/invalid token
    }
  }
  updateNavAuthState();
  renderTierGrid();
  renderVideoGrid();
}

/* ---------------- RENDER: STATIC SECTIONS ---------------- */

function renderBeltStrip() {
  const el = document.getElementById("belt-strip");
  BELTS.forEach((b) => {
    const chip = document.createElement("span");
    chip.className = "belt-chip";
    chip.style.background = b.hex;
    if (b.outline) chip.style.border = "1px solid #55524A";
    el.appendChild(chip);
  });
  const tail = document.createElement("span");
  tail.className = "belt-strip-tail";
  tail.textContent = "white → black, 8 ranks, no skipping";
  el.appendChild(tail);
}

function renderFilters() {
  const beltEl = document.getElementById("belt-filters");
  beltEl.innerHTML = "";
  beltEl.appendChild(makePill("All ranks", state.beltFilter === "all", () => setBeltFilter("all")));
  BELTS.forEach((b) => {
    const pill = makePill(b.name, state.beltFilter === b.id, () => setBeltFilter(b.id));
    const dot = document.createElement("span");
    dot.className = "pill-dot";
    dot.style.background = b.hex;
    if (b.outline) dot.style.border = "1px solid #55524A";
    pill.prepend(dot);
    beltEl.appendChild(pill);
  });

  const typeEl = document.getElementById("type-filters");
  typeEl.innerHTML = "";
  typeEl.appendChild(makePill("All types", state.typeFilter === "all", () => setTypeFilter("all"), true));
  TYPES.forEach((t) => {
    typeEl.appendChild(makePill(t, state.typeFilter === t, () => setTypeFilter(t), true));
  });
}

function makePill(label, active, onClick, subtle) {
  const btn = document.createElement("button");
  btn.className = "pill" + (active ? " active" : "") + (subtle ? " subtle" : "");
  btn.textContent = label;
  btn.addEventListener("click", onClick);
  return btn;
}

function setBeltFilter(id) { state.beltFilter = id; renderFilters(); renderVideoGrid(); }
function setTypeFilter(t) { state.typeFilter = t; renderFilters(); renderVideoGrid(); }

function renderTierGrid() {
  const el = document.getElementById("tier-grid");
  el.innerHTML = "";
  state.tiers.forEach((tier) => {
    const belt = beltById(tier.belt);
    const isCurrent = state.currentUser && state.currentUser.subscriptionTier === tier.slug;

    const card = document.createElement("div");
    card.className = "tier-card" + (tier.featured ? " featured" : "");

    if (tier.featured) {
      const flag = document.createElement("span");
      flag.className = "tier-flag";
      flag.textContent = "Most trained";
      card.appendChild(flag);
    }

    card.innerHTML += `
      <div class="tier-name-row">
        <span class="pill-dot" style="background:${belt.hex};${belt.outline ? "border:1px solid #55524A;" : ""}"></span>
        <h3 class="disp tier-name">${escapeHTML(tier.name.toUpperCase())}</h3>
      </div>
      <p class="tier-tagline">${escapeHTML(tier.tagline)}</p>
      <div class="tier-price-row">
        <span class="disp tier-price">${formatPrice(tier.priceCents)}</span>
        <span class="tier-period">${escapeHTML(tier.period)}</span>
      </div>
      <ul class="tier-features">
        ${tier.features.map((f) => `<li>${escapeHTML(f)}</li>`).join("")}
      </ul>
    `;

    const btn = document.createElement("button");
    btn.className = "tier-btn" + (tier.featured ? " primary" : "");
    btn.textContent = isCurrent ? "Current plan" : tier.priceCents === 0 ? "Start free" : "Subscribe";
    btn.disabled = !!isCurrent;
    btn.addEventListener("click", () => handleTierSelect(tier));
    card.appendChild(btn);

    el.appendChild(card);
  });
}

function formatPrice(cents) {
  if (cents === 0) return "Free";
  return "$" + (cents / 100).toFixed(0);
}

function escapeHTML(str) {
  const div = document.createElement("div");
  div.textContent = str;
  return div.innerHTML;
}

/* ---------------- RENDER: VIDEO GRID ---------------- */

function renderVideoGrid() {
  const grid = document.getElementById("video-grid");
  const emptyNote = document.getElementById("empty-note");
  grid.innerHTML = "";

  const filtered = state.videos.filter(
    (v) => (state.beltFilter === "all" || v.belt === state.beltFilter) &&
           (state.typeFilter === "all" || v.type === state.typeFilter)
  );

  emptyNote.classList.toggle("hidden", filtered.length > 0);

  filtered.forEach((v) => {
    const belt = beltById(v.belt);
    const locked = isLocked(v);

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
      </div>
      <div class="video-body">
        <span class="video-type">${escapeHTML(v.type)}</span>
        <h3 class="video-title">${escapeHTML(v.title)}</h3>
        <p class="video-instructor">${escapeHTML(v.instructor)}</p>
      </div>
    `;
    grid.appendChild(card);
  });
}

function playIconHTML() {
  return `<span style="width:0;height:0;border-top:7px solid transparent;border-bottom:7px solid transparent;border-left:11px solid #EDEAE2;display:inline-block;margin-left:2px;"></span>`;
}
function lockIconHTML() {
  return `<span class="lock-wrap"><span class="icon-lock"></span><span class="icon-lock-body"></span></span>`;
}

function isLocked(video) {
  return video.premium && !(state.currentUser && state.currentUser.subscriptionTier);
}

/* ---------------- MODALS ---------------- */

const modalRoot = document.getElementById("modal-root");

function closeModal() {
  modalRoot.innerHTML = "";
  state.authMode = null;
  state.authError = "";
  state.authBusy = false;
  state.checkoutTier = null;
  state.checkoutStep = "form";
}

function openOverlay(bodyHTML, onMount) {
  modalRoot.innerHTML = `
    <div class="modal-overlay" id="overlay">
      <div class="modal-box" id="modal-box">
        <button class="modal-close" id="modal-close-btn" aria-label="Close">×</button>
        ${bodyHTML}
      </div>
    </div>
  `;
  document.getElementById("overlay").addEventListener("click", (e) => {
    if (e.target.id === "overlay") closeModal();
  });
  document.getElementById("modal-close-btn").addEventListener("click", closeModal);
  if (onMount) onMount();
}

/* --- Video modal --- */

function openVideoModal(video) {
  const belt = beltById(video.belt);

  if (isLocked(video)) {
    openOverlay(`
      <div class="modal-body" style="text-align:center;padding:50px 32px;">
        <div class="lock-wrap" style="margin-bottom:16px;">${lockIconHTML()}</div>
        <h3 class="disp" style="font-size:28px;margin:0 0 10px;">MEMBERS ONLY</h3>
        <p style="color:var(--muted);margin-bottom:26px;max-width:380px;margin-left:auto;margin-right:auto;">
          "${escapeHTML(video.title)}" is part of the full library. Join Black Belt membership to unlock every rank.
        </p>
        <button class="btn btn-primary" id="go-membership">View membership</button>
      </div>
    `, () => {
      document.getElementById("go-membership").addEventListener("click", () => {
        closeModal();
        scrollToId("membership");
      });
    });
  } else {
    openOverlay(`
      <div>
        <div class="video-thumb" style="height:260px;background:linear-gradient(135deg, ${belt.hex} 0%, #121212 140%);">
          <div class="video-play-circle" style="width:68px;height:68px;">${playIconHTML()}</div>
        </div>
        <div class="modal-body">
          <span class="video-type">${escapeHTML(video.type)} · ${belt.name} belt · Lesson ${String(video.lesson).padStart(2, "0")}</span>
          <h3 style="font-size:22px;margin:8px 0 6px;">${escapeHTML(video.title)}</h3>
          <p style="color:var(--muted);font-size:14px;">${escapeHTML(video.instructor)} · ${escapeHTML(video.duration)}</p>
        </div>
      </div>
    `);
  }
}

/* --- Auth modal --- */

function openAuthModal(mode) {
  state.authMode = mode;
  state.authError = "";
  renderAuthModal();
}

function renderAuthModal() {
  const isSignup = state.authMode === "signup";
  openOverlay(`
    <div class="auth-tabs">
      <button class="auth-tab ${!isSignup ? "active" : ""}" id="tab-signin">Sign in</button>
      <button class="auth-tab ${isSignup ? "active" : ""}" id="tab-signup">Create account</button>
    </div>
    <form class="modal-body" id="auth-form">
      <h3 class="disp" style="font-size:26px;margin:0 0 4px;">${isSignup ? "CREATE ACCOUNT" : "WELCOME BACK"}</h3>
      <p style="color:var(--muted);font-size:14px;margin-bottom:22px;">
        ${isSignup ? "Sign up to start training and track your rank." : "Sign in to your dojo account."}
      </p>
      ${isSignup ? field("name", "Full name", "text", "Jane Kim") : ""}
      ${field("email", "Email", "email", "jane@example.com")}
      ${field("password", "Password", "password", isSignup ? "At least 8 characters" : "••••••••")}
      ${state.authError ? `<p class="field-error">${escapeHTML(state.authError)}</p>` : ""}
      <button type="submit" class="btn btn-primary btn-block" ${state.authBusy ? "disabled" : ""}>
        ${state.authBusy ? "Please wait…" : isSignup ? "Create account" : "Sign in"}
      </button>
    </form>
  `, () => {
    document.getElementById("tab-signin").addEventListener("click", () => openAuthModal("signin"));
    document.getElementById("tab-signup").addEventListener("click", () => openAuthModal("signup"));
    document.getElementById("auth-form").addEventListener("submit", handleAuthSubmit);
  });
}

function field(id, label, type, placeholder) {
  return `
    <label class="field">
      <span class="field-label">${label}</span>
      <input class="field-input" id="field-${id}" type="${type}" placeholder="${placeholder}" required />
    </label>
  `;
}

async function handleAuthSubmit(e) {
  e.preventDefault();
  const email = document.getElementById("field-email").value.trim();
  const password = document.getElementById("field-password").value;
  const isSignup = state.authMode === "signup";

  state.authBusy = true;
  state.authError = "";
  renderAuthModal();

  try {
    const payload = isSignup
      ? { name: document.getElementById("field-name").value.trim(), email, password }
      : { email, password };

    const data = await api(isSignup ? "/auth/signup" : "/auth/login", {
      method: "POST",
      body: JSON.stringify(payload),
    });

    setToken(data.token);
    state.currentUser = data.user;
    updateNavAuthState();
    renderTierGrid();
    renderVideoGrid();

    if (state.pendingTier) {
      const tier = state.pendingTier;
      state.pendingTier = null;
      if (tier.priceCents === 0) {
        await subscribeToTier(tier);
        closeModal();
      } else {
        openCheckoutModal(tier);
      }
    } else {
      closeModal();
    }
  } catch (err) {
    state.authBusy = false;
    state.authError = err.message;
    renderAuthModal();
  }
}

/* --- Checkout modal --- */

async function handleTierSelect(tier) {
  if (!state.currentUser) {
    state.pendingTier = tier;
    openAuthModal("signup");
    return;
  }
  if (tier.priceCents === 0) {
    await subscribeToTier(tier);
    return;
  }
  openCheckoutModal(tier);
}

async function subscribeToTier(tier) {
  try {
    const data = await api("/subscriptions", {
      method: "POST",
      body: JSON.stringify({ tierSlug: tier.slug }),
    });
    state.currentUser = data.user;
    updateNavAuthState();
    renderTierGrid();
    renderVideoGrid();
  } catch (err) {
    alert(err.message);
  }
}

function openCheckoutModal(tier) {
  state.checkoutTier = tier;
  state.checkoutStep = "form";
  renderCheckoutModal();
}

function renderCheckoutModal() {
  const tier = state.checkoutTier;

  if (state.checkoutStep === "form") {
    openOverlay(`
      <form class="modal-body" id="checkout-form">
        <h3 class="disp" style="font-size:26px;margin:0 0 4px;">JOIN ${escapeHTML(tier.name.toUpperCase())}</h3>
        <p style="color:var(--muted);font-size:14px;margin-bottom:22px;">${formatPrice(tier.priceCents)}${escapeHTML(tier.period)} · signed in as ${escapeHTML(state.currentUser.email)} · cancel anytime</p>
        ${field("cc-name", "Name on card", "text", "Jane Kim")}
        ${field("cc-number", "Card number", "text", "4242 4242 4242 4242")}
        <div class="field-row">
          ${field("cc-exp", "Expiry", "text", "MM/YY")}
          ${field("cc-cvc", "CVC", "text", "123")}
        </div>
        <button type="submit" class="btn btn-primary btn-block">Confirm & subscribe</button>
        <p class="trust-note">Proof-of-concept checkout — no real payment is processed. The subscription is recorded for real in the database.</p>
      </form>
    `, () => {
      document.getElementById("checkout-form").addEventListener("submit", async (e) => {
        e.preventDefault();
        state.checkoutStep = "processing";
        renderCheckoutModal();

        // Simulated card-network delay -- swap this block for a real
        // payment-provider call (e.g. Stripe) when going to production.
        setTimeout(async () => {
          try {
            await subscribeToTier(tier);
            state.checkoutStep = "success";
            renderCheckoutModal();
          } catch (err) {
            state.checkoutStep = "form";
            renderCheckoutModal();
            alert(err.message);
          }
        }, 1400);
      });
    });
  } else if (state.checkoutStep === "processing") {
    openOverlay(`
      <div class="modal-body" style="text-align:center;padding:70px 32px;">
        <div class="spinner"></div>
        <p style="color:var(--muted);">Processing your membership…</p>
      </div>
    `);
  } else if (state.checkoutStep === "success") {
    openOverlay(`
      <div class="modal-body" style="text-align:center;padding:56px 32px;">
        <div class="success-icon">✓</div>
        <h3 class="disp" style="font-size:26px;margin:0 0 8px;">YOU'RE IN.</h3>
        <p style="color:var(--muted);margin-bottom:26px;">${escapeHTML(tier.name)} membership is active. The full library just unlocked.</p>
        <button class="btn btn-light" id="start-watching">Start watching</button>
      </div>
    `, () => {
      document.getElementById("start-watching").addEventListener("click", () => {
        closeModal();
        scrollToId("library");
      });
    });
  }
}

/* ---------------- NAV AUTH STATE ---------------- */

function updateNavAuthState() {
  const badge = document.getElementById("tier-badge");
  const signInBtn = document.getElementById("nav-signin");
  const joinBtn = document.getElementById("nav-join");

  if (state.currentUser) {
    const sub = state.currentUser.subscriptionTier;
    if (sub) {
      const tier = state.tiers.find((t) => t.slug === sub);
      badge.textContent = tier ? tier.name : sub;
      badge.classList.remove("hidden");
    } else {
      badge.classList.add("hidden");
    }
    signInBtn.textContent = "Log out (" + state.currentUser.name.split(" ")[0] + ")";
    signInBtn.onclick = handleLogout;
    joinBtn.textContent = state.currentUser.subscriptionTier ? "Manage" : "Join now";
  } else {
    badge.classList.add("hidden");
    signInBtn.textContent = "Sign in";
    signInBtn.onclick = () => openAuthModal("signin");
    joinBtn.textContent = "Join now";
  }
}

async function handleLogout() {
  try { await api("/auth/logout", { method: "POST" }); } catch { /* token already invalid, fine */ }
  clearToken();
  state.currentUser = null;
  updateNavAuthState();
  renderTierGrid();
  renderVideoGrid();
}

/* ---------------- SCROLL / NAV WIRING ---------------- */

function scrollToId(id) {
  document.getElementById(id).scrollIntoView({ behavior: "smooth", block: "start" });
}

function wireNav() {
  document.getElementById("nav-home").addEventListener("click", () => window.scrollTo({ top: 0, behavior: "smooth" }));
  document.getElementById("nav-library").addEventListener("click", () => scrollToId("library"));
  document.getElementById("nav-membership").addEventListener("click", () => scrollToId("membership"));
  document.getElementById("hero-watch").addEventListener("click", () => scrollToId("library"));
  document.getElementById("hero-membership").addEventListener("click", () => scrollToId("membership"));
  document.getElementById("nav-join").addEventListener("click", () => scrollToId("membership"));
  document.getElementById("nav-signin").addEventListener("click", () => openAuthModal("signin"));
}

document.addEventListener("DOMContentLoaded", init);
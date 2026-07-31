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
  authMode: null,        // null | "signin" | "signup" | "verify" | "forgot" | "reset"
  authError: "",
  authNotice: "",
  authBusy: false,
  pendingTier: null,      // tier the user tried to buy before being signed in
  resetEmail: "",         // carried from "forgot" step into "reset" step
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

async function redirectIfAuthenticated() {
  if (!getToken()) return false;
  try {
    await api("/auth/me"); // confirms the token is still valid, not just present
    window.location.replace("dashboard.html");
    return true;
  } catch {
    clearToken(); // stale/expired token -- let them see the public page normally
    return false;
  }
}

async function init() {
  if (await redirectIfAuthenticated()) return; // navigating away; don't render the public page at all

  renderBeltStrip();
  wireNav();
  handleCheckoutRedirect();

  const grid = document.getElementById("video-grid");
  grid.innerHTML = `<p class="loading-note">Loading the library…</p>`;

  try {
    const [videos, tiers, content] = await Promise.all([
      api("/videos"),
      api("/tiers"),
      api("/content").catch(() => ({})), // CMS text is optional -- fall back to the HTML defaults
    ]);
    state.videos = videos;
    state.tiers = tiers;
    applyContent(content);
  } catch (err) {
    grid.innerHTML = `<p class="loading-note">Couldn't reach the server. Is the backend running?</p>`;
    console.error(err);
    return;
  }

  api("/events").then(renderEventsCarousel).catch((err) => console.error("events failed to load", err));

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

function applyContent(map) {
  document.querySelectorAll("[data-content-key]").forEach((el) => {
    const key = el.getAttribute("data-content-key");
    if (map[key]) el.textContent = map[key]; // textContent only -- never innerHTML from server data
  });
}

function handleCheckoutRedirect() {
  const params = new URLSearchParams(window.location.search);
  const status = params.get("checkout");
  if (!status) return;
  window.history.replaceState({}, "", window.location.pathname);
  if (status === "success") {
    showToast("Payment received — welcome to Black Belt membership.");
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

function renderTierGrid() {
  const el = document.getElementById("tier-grid");
  el.innerHTML = "";
  state.tiers.forEach((tier) => {
    const belt = beltById(tier.belt);
    const user = state.currentUser;
    const onThisTier = user && user.subscriptionTier === tier.slug;
    // A trial only counts as "current" while still active; once expired the
    // card should stop saying "Current plan" and instead point at upgrading.
    const isCurrent = onThisTier && (tier.priceCents > 0 || user.subscriptionActive);
    const trialExhausted = tier.priceCents === 0 && user && user.trialUsed && !isCurrent;

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
      ${trialExhausted ? `<p style="font-size:12px;color:var(--muted);margin:-16px 0 16px;">Already used — pick a paid plan below.</p>` : ""}
    `;

    const btn = document.createElement("button");
    btn.className = "tier-btn" + (tier.featured ? " primary" : "");
    btn.textContent = isCurrent
      ? "Current plan"
      : trialExhausted
        ? "Trial used"
        : tier.priceCents === 0
          ? "Start 7-day trial"
          : "Subscribe";
    btn.disabled = isCurrent || trialExhausted;
    btn.addEventListener("click", () => handleTierSelect(tier));
    card.appendChild(btn);

    el.appendChild(card);
  });
}

function formatPrice(cents) {
  if (cents === 0) return "Free";
  return "R" + (cents / 100).toFixed(0);
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

  // Public page shows a fixed, small teaser -- not a browsable library.
  // The real library lives behind sign-in, in the student dashboard.
  const preview = state.videos.slice(0, 3);
  emptyNote.classList.toggle("hidden", preview.length > 0);

  preview.forEach((v) => {
    const belt = beltById(v.belt);

    const card = document.createElement("div");
    card.className = "video-card";
    card.tabIndex = 0;
    card.setAttribute("role", "button");
    card.addEventListener("click", () => openVideoModal(v));
    card.addEventListener("keydown", (e) => { if (e.key === "Enter") openVideoModal(v); });

    card.innerHTML = `
      <div class="video-thumb" style="background: linear-gradient(135deg, ${belt.hex} 0%, #121212 130%);">
        <div class="video-play-circle">${lockIconHTML()}</div>
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
  return video.premium && !(state.currentUser && state.currentUser.subscriptionActive);
}

/* ---------------- EVENTS CAROUSEL ---------------- */

function formatEventDate(dateStr) {
  if (!dateStr) return "";
  const d = new Date(dateStr + "T00:00:00");
  if (isNaN(d)) return "";
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
}

function renderEventsCarousel(events) {
  const section = document.getElementById("events");
  const track = document.getElementById("events-track");
  const dotsEl = document.getElementById("events-dots");
  if (!track) return;

  if (!events || events.length === 0) {
    if (section) section.classList.add("hidden");
    return;
  }

  track.innerHTML = events.map((e) => `
    <article class="event-card">
      <div class="event-card-banner" ${e.imageUrl ? `style="background-image:url('${escapeHTML(e.imageUrl)}');background-size:cover;background-position:center;"` : ""}>
        ${e.eventDate ? `<span class="event-card-date">${escapeHTML(formatEventDate(e.eventDate))}</span>` : ""}
      </div>
      <div class="event-card-body">
        ${e.location ? `<span class="event-card-location">${escapeHTML(e.location)}</span>` : ""}
        <h3 class="event-card-title">${escapeHTML(e.title)}</h3>
        ${e.description ? `<p class="event-card-desc">${escapeHTML(e.description)}</p>` : ""}
        ${e.linkUrl ? `<a class="event-card-link" href="${escapeHTML(e.linkUrl)}" target="_blank" rel="noopener noreferrer">Learn more →</a>` : ""}
      </div>
    </article>
  `).join("");

  dotsEl.innerHTML = "";
  events.forEach((_, i) => {
    const dot = document.createElement("button");
    dot.className = "carousel-dot" + (i === 0 ? " active" : "");
    dot.setAttribute("aria-label", `Go to event ${i + 1}`);
    dot.addEventListener("click", () => scrollCarouselToIndex(i));
    dotsEl.appendChild(dot);
  });

  wireCarouselControls();
}

function scrollCarouselToIndex(i) {
  const track = document.getElementById("events-track");
  const card = track.children[i];
  if (card) track.scrollTo({ left: card.offsetLeft - track.offsetLeft, behavior: "smooth" });
}

function wireCarouselControls() {
  const track = document.getElementById("events-track");
  const prevBtn = document.getElementById("events-prev");
  const nextBtn = document.getElementById("events-next");
  const dotsEl = document.getElementById("events-dots");
  if (!track || track.dataset.wired) return;
  track.dataset.wired = "true";

  function cardWidth() {
    const card = track.children[0];
    return card ? card.getBoundingClientRect().width + 20 : 300; // + gap
  }

  prevBtn.addEventListener("click", () => track.scrollBy({ left: -cardWidth(), behavior: "smooth" }));
  nextBtn.addEventListener("click", () => track.scrollBy({ left: cardWidth(), behavior: "smooth" }));

  function updateActiveState() {
    const index = Math.round(track.scrollLeft / cardWidth());
    [...dotsEl.children].forEach((dot, i) => dot.classList.toggle("active", i === index));
    prevBtn.disabled = track.scrollLeft <= 4;
    nextBtn.disabled = track.scrollLeft >= track.scrollWidth - track.clientWidth - 4;
  }

  let scrollTimer;
  track.addEventListener("scroll", () => {
    clearTimeout(scrollTimer);
    scrollTimer = setTimeout(updateActiveState, 80);
  });
  updateActiveState();
}

/* ---------------- MODALS ---------------- */

const modalRoot = document.getElementById("modal-root");

function closeModal() {
  modalRoot.innerHTML = "";
  state.authMode = null;
  state.authError = "";
  state.authNotice = "";
  state.authBusy = false;
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

  openOverlay(`
    <div class="modal-body" style="text-align:center;padding:50px 32px;">
      <div class="lock-wrap" style="margin-bottom:16px;">${lockIconHTML()}</div>
      <span class="video-type">${escapeHTML(video.type)} · ${belt.name} belt · Lesson ${String(video.lesson).padStart(2, "0")}</span>
      <h3 class="disp" style="font-size:26px;margin:10px 0 6px;">${escapeHTML(video.title)}</h3>
      <p style="color:var(--muted);font-size:14px;margin-bottom:14px;">${escapeHTML(video.instructor)} · ${escapeHTML(video.duration)}</p>
      ${video.caption ? `<p style="font-size:14px;color:#D4D1C6;margin-bottom:26px;max-width:380px;margin-left:auto;margin-right:auto;">${escapeHTML(video.caption)}</p>` : ""}
      <button class="btn btn-hero" id="go-signup">Sign up to watch</button>
    </div>
  `, () => {
    document.getElementById("go-signup").addEventListener("click", () => {
      closeModal();
      openAuthModal("signup");
    });
  });
}

/** Turns a plain YouTube/Vimeo URL into an embeddable iframe. Falls back to a plain link for anything else. */
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
    return "";
  }
}

/* --- Auth modal (sign in / sign up / verify / forgot / reset) --- */

function openAuthModal(mode) {
  state.authMode = mode;
  state.authError = "";
  state.authNotice = "";
  renderAuthModal();
}

function field(id, label, type, placeholder) {
  return `
    <label class="field">
      <span class="field-label">${label}</span>
      <input class="field-input" id="field-${id}" type="${type}" placeholder="${placeholder}" required />
    </label>
  `;
}

function renderAuthModal() {
  const mode = state.authMode;
  const messages = state.authError
    ? `<p class="field-error">${escapeHTML(state.authError)}</p>`
    : state.authNotice
      ? `<p class="field-notice">${escapeHTML(state.authNotice)}</p>`
      : "";

  if (mode === "signin" || mode === "signup") {
    const isSignup = mode === "signup";
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
        ${messages}
        <button type="submit" class="btn btn-primary btn-block" ${state.authBusy ? "disabled" : ""}>
          ${state.authBusy ? "Please wait…" : isSignup ? "Create account" : "Sign in"}
        </button>
        ${!isSignup ? `<button type="button" class="link-btn" id="go-forgot" style="margin-top:14px;">Forgot password?</button>` : ""}
      </form>
    `, () => {
      document.getElementById("tab-signin").addEventListener("click", () => openAuthModal("signin"));
      document.getElementById("tab-signup").addEventListener("click", () => openAuthModal("signup"));
      document.getElementById("auth-form").addEventListener("submit", handleAuthSubmit);
      const forgotBtn = document.getElementById("go-forgot");
      if (forgotBtn) forgotBtn.addEventListener("click", () => openAuthModal("forgot"));
    });
    return;
  }

  if (mode === "verify") {
    openOverlay(`
      <form class="modal-body" id="verify-form">
        <h3 class="disp" style="font-size:26px;margin:0 0 4px;">CHECK YOUR EMAIL</h3>
        <p style="color:var(--muted);font-size:14px;margin-bottom:22px;">
          We sent a 6-digit code to <strong style="color:var(--bone);">${escapeHTML(state.currentUser?.email || "")}</strong>.
        </p>
        ${field("otp", "Verification code", "text", "123456")}
        ${messages}
        <button type="submit" class="btn btn-primary btn-block" ${state.authBusy ? "disabled" : ""}>
          ${state.authBusy ? "Verifying…" : "Verify email"}
        </button>
        <button type="button" class="link-btn" id="resend-otp" style="margin-top:14px;">Resend code</button>
        <button type="button" class="link-btn" id="skip-verify" style="margin-top:8px; display:block;">Skip for now</button>
      </form>
    `, () => {
      document.getElementById("verify-form").addEventListener("submit", handleVerifySubmit);
      document.getElementById("resend-otp").addEventListener("click", handleResendOtp);
      document.getElementById("skip-verify").addEventListener("click", () => { window.location.href = "dashboard.html"; });
    });
    return;
  }

  if (mode === "forgot") {
    openOverlay(`
      <form class="modal-body" id="forgot-form">
        <h3 class="disp" style="font-size:26px;margin:0 0 4px;">RESET PASSWORD</h3>
        <p style="color:var(--muted);font-size:14px;margin-bottom:22px;">Enter your account email and we'll send a reset code.</p>
        ${field("email", "Email", "email", "jane@example.com")}
        ${messages}
        <button type="submit" class="btn btn-primary btn-block" ${state.authBusy ? "disabled" : ""}>
          ${state.authBusy ? "Sending…" : "Send reset code"}
        </button>
        <button type="button" class="link-btn" id="back-signin" style="margin-top:14px;">Back to sign in</button>
      </form>
    `, () => {
      document.getElementById("forgot-form").addEventListener("submit", handleForgotSubmit);
      document.getElementById("back-signin").addEventListener("click", () => openAuthModal("signin"));
    });
    return;
  }

  if (mode === "reset") {
    openOverlay(`
      <form class="modal-body" id="reset-form">
        <h3 class="disp" style="font-size:26px;margin:0 0 4px;">ENTER NEW PASSWORD</h3>
        <p style="color:var(--muted);font-size:14px;margin-bottom:22px;">
          Enter the code sent to <strong style="color:var(--bone);">${escapeHTML(state.resetEmail)}</strong> and choose a new password.
        </p>
        ${field("otp", "Reset code", "text", "123456")}
        ${field("password", "New password", "password", "At least 8 characters")}
        ${messages}
        <button type="submit" class="btn btn-primary btn-block" ${state.authBusy ? "disabled" : ""}>
          ${state.authBusy ? "Resetting…" : "Reset password"}
        </button>
      </form>
    `, () => {
      document.getElementById("reset-form").addEventListener("submit", handleResetSubmit);
    });
    return;
  }
}

async function handleAuthSubmit(e) {
  e.preventDefault();
  const isSignup = state.authMode === "signup";
  const name = isSignup ? document.getElementById("field-name").value.trim() : null;
  const email = document.getElementById("field-email").value.trim();
  const password = document.getElementById("field-password").value;

  state.authBusy = true;
  state.authError = "";
  renderAuthModal();

  try {
    const payload = isSignup ? { name, email, password } : { email, password };

    const data = await api(isSignup ? "/auth/signup" : "/auth/login", {
      method: "POST",
      body: JSON.stringify(payload),
    });

    setToken(data.token);
    state.currentUser = data.user;
    updateNavAuthState();
    renderTierGrid();
    renderVideoGrid();

    if (isSignup) {
      state.authBusy = false;
      openAuthModal("verify");
      return;
    }

    await resumePendingTierOrClose();
  } catch (err) {
    state.authBusy = false;
    state.authError = err.message;
    renderAuthModal();
  }
}

async function handleVerifySubmit(e) {
  e.preventDefault();
  const code = document.getElementById("field-otp").value.trim();
  state.authBusy = true;
  state.authError = "";
  renderAuthModal();
  try {
    await api("/auth/verify-email", { method: "POST", body: JSON.stringify({ code }) });
    state.currentUser.emailVerified = true;
    updateNavAuthState();
    await resumePendingTierOrClose();
  } catch (err) {
    state.authBusy = false;
    state.authError = err.message;
    renderAuthModal();
  }
}

async function handleResendOtp() {
  state.authError = "";
  try {
    await api("/auth/resend-verification", { method: "POST" });
    state.authNotice = "A new code is on its way.";
  } catch (err) {
    state.authError = err.message;
  }
  renderAuthModal();
}

async function handleForgotSubmit(e) {
  e.preventDefault();
  const email = document.getElementById("field-email").value.trim();
  state.authBusy = true;
  state.authError = "";
  renderAuthModal();
  try {
    await api("/auth/forgot-password", { method: "POST", body: JSON.stringify({ email }) });
    state.resetEmail = email;
    state.authBusy = false;
    openAuthModal("reset");
  } catch (err) {
    state.authBusy = false;
    state.authError = err.message;
    renderAuthModal();
  }
}

async function handleResetSubmit(e) {
  e.preventDefault();
  const code = document.getElementById("field-otp").value.trim();
  const newPassword = document.getElementById("field-password").value;
  state.authBusy = true;
  state.authError = "";
  renderAuthModal();
  try {
    await api("/auth/reset-password", {
      method: "POST",
      body: JSON.stringify({ email: state.resetEmail, code, newPassword }),
    });
    state.authBusy = false;
    state.authError = "";
    openAuthModal("signin");
    state.authNotice = "Password reset. Sign in with your new password.";
    renderAuthModal();
  } catch (err) {
    state.authBusy = false;
    state.authError = err.message;
    renderAuthModal();
  }
}

async function resumePendingTierOrClose() {
  if (state.pendingTier) {
    const tier = state.pendingTier;
    state.pendingTier = null;
    if (tier.priceCents === 0) {
      await subscribeToFreeTier(tier);
      window.location.href = "dashboard.html";
    } else {
      closeModal();
      await startCheckout(tier); // redirects to Stripe; returns to dashboard.html on completion
    }
  } else {
    window.location.href = "dashboard.html";
  }
}

/* --- Subscriptions / Stripe checkout --- */

async function handleTierSelect(tier) {
  if (!state.currentUser) {
    state.pendingTier = tier;
    openAuthModal("signup");
    return;
  }
  if (tier.priceCents === 0) {
    await subscribeToFreeTier(tier);
    return;
  }
  await startCheckout(tier);
}

async function subscribeToFreeTier(tier) {
  try {
    const data = await api("/subscriptions", { method: "POST", body: JSON.stringify({ tierSlug: tier.slug }) });
    state.currentUser = data.user;
    updateNavAuthState();
    renderTierGrid();
    renderVideoGrid();
  } catch (err) {
    alert(err.message);
  }
}

// Paid tiers redirect to Stripe's own hosted checkout page -- card details
// are typed there, never collected by this app.
async function startCheckout(tier) {
  try {
    const data = await api("/checkout/create-session", { method: "POST", body: JSON.stringify({ tierSlug: tier.slug }) });
    if (data.url) {
      window.location.href = data.url;
    } else {
      alert("Checkout is not fully configured yet.");
    }
  } catch (err) {
    alert(err.message);
  }
}

/* ---------------- NAV AUTH STATE ---------------- */

function updateNavAuthState() {
  const badge = document.getElementById("tier-badge");
  const signInBtn = document.getElementById("nav-signin");
  const joinBtn = document.getElementById("nav-join");
  const dashboardBtn = document.getElementById("nav-dashboard");
  dashboardBtn.classList.toggle("hidden", !state.currentUser);

  if (state.currentUser) {
    const sub = state.currentUser.subscriptionTier;
    if (sub === "trial" && state.currentUser.subscriptionActive) {
      const daysLeft = Math.max(0, Math.ceil((new Date(state.currentUser.trialEndsAt) - Date.now()) / 86400000));
      badge.textContent = `Trial · ${daysLeft} day${daysLeft === 1 ? "" : "s"} left`;
      badge.classList.remove("hidden");
      badge.onclick = null;
      badge.style.cursor = "default";
    } else if (sub === "trial" && !state.currentUser.subscriptionActive) {
      badge.textContent = "Trial expired";
      badge.classList.remove("hidden");
      badge.onclick = () => scrollToId("membership");
      badge.style.cursor = "pointer";
    } else if (sub) {
      const tier = state.tiers.find((t) => t.slug === sub);
      badge.textContent = tier ? tier.name : sub;
      badge.classList.remove("hidden");
      badge.onclick = null;
      badge.style.cursor = "default";
    } else if (!state.currentUser.emailVerified) {
      badge.textContent = "Verify email";
      badge.classList.remove("hidden");
      badge.onclick = () => openAuthModal("verify");
      badge.style.cursor = "pointer";
    } else {
      badge.classList.add("hidden");
    }
    signInBtn.textContent = "Log out (" + state.currentUser.name.split(" ")[0] + ")";
    signInBtn.onclick = handleLogout;
    joinBtn.textContent = state.currentUser.subscriptionActive ? "Manage" : "Join now";
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
  document.getElementById("nav-join").addEventListener("click", () => scrollToId("membership"));
  document.getElementById("library-cta").addEventListener("click", () => openAuthModal("signup"));
  document.getElementById("nav-signin").addEventListener("click", () => openAuthModal("signin"));
  document.getElementById("nav-dashboard").addEventListener("click", () => { window.location.href = "dashboard.html"; });

  const menuToggle = document.getElementById("menu-toggle");
  const navLinks = document.getElementById("nav-links");

  function setMenuOpen(open) {
    navLinks.classList.toggle("open", open);
    menuToggle.classList.toggle("active", open);
    menuToggle.setAttribute("aria-expanded", String(open));
  }

  menuToggle.addEventListener("click", () => setMenuOpen(!navLinks.classList.contains("open")));

  navLinks.addEventListener("click", (e) => {
    if (e.target.closest("button")) setMenuOpen(false);
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

// Some browsers restore a page from cache on back/forward navigation
// without re-running the scripts above -- this catches that case too.
window.addEventListener("pageshow", (e) => {
  if (e.persisted) redirectIfAuthenticated();
});
const API_BASE = window.location.hostname === "localhost"
  ? "http://localhost:8000/api"
  : "https://fullstack-backend.onrender.com/api";

const list = document.getElementById("item-list");
const form = document.getElementById("item-form");
const input = document.getElementById("item-name");

async function loadItems() {
  const res = await fetch(`${API_BASE}/items`);
  const items = await res.json();
  list.innerHTML = "";
  items.forEach(item => {
    const li = document.createElement("li");
    li.textContent = item.name;
    list.appendChild(li);
  });
}

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  await fetch(`${API_BASE}/items`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ name: input.value })
  });
  input.value = "";
  loadItems();
});

loadItems();


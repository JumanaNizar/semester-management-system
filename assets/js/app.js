document.addEventListener("DOMContentLoaded", function () {
  // Mobile menu toggle
  var btn = document.getElementById("menuBtn");
  var links = document.getElementById("navLinks");
  if (btn && links) {
    btn.addEventListener("click", function () {
      links.classList.toggle("open");
    });
  }

  // Add the CSRF token to every POST form
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) {
    document.querySelectorAll("form").forEach(function (f) {
      var method = (f.getAttribute("method") || "get").toLowerCase();
      if (method === "post" && !f.querySelector('input[name="csrf_token"]')) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = "csrf_token";
        input.value = meta.content;
        f.appendChild(input);
      }
    });
  }

  // Confirm before any button/link marked with data-confirm
  document.querySelectorAll("[data-confirm]").forEach(function (el) {
    el.addEventListener("click", function (e) {
      if (!confirm(el.getAttribute("data-confirm"))) e.preventDefault();
    });
  });
});
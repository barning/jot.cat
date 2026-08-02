(() => {
  const clipboard = navigator.clipboard;

  if (clipboard && typeof clipboard.writeText === "function") {
    document.querySelectorAll("[data-copy-code]").forEach((button) => {
      button.hidden = false;
      button.addEventListener("click", async () => {
        const status = document.querySelector("[data-copy-status]");

        try {
          await clipboard.writeText(button.dataset.copyCode || "");
          if (status) status.textContent = "Code copied.";
        } catch {
          if (status) status.textContent = "Copy failed. Select the code and copy it manually.";
        }
      });
    });
  }

  document.querySelectorAll("[data-save-form]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (form.dataset.submitting === "true") {
        event.preventDefault();
        return;
      }

      const button = event.submitter;
      if (!(button instanceof HTMLButtonElement)) return;

      form.dataset.submitting = "true";
      form.setAttribute("aria-busy", "true");
      button.disabled = true;
      button.classList.add("is-loading");
      const status = form.querySelector("[data-save-status]");
      if (status) status.textContent = form.dataset.savingStatus || "Saving article…";
    });
  });

  window.addEventListener("pageshow", () => {
    document.querySelectorAll("[data-save-form]").forEach((form) => {
      delete form.dataset.submitting;
      form.removeAttribute("aria-busy");
      const button = form.querySelector('button[type="submit"]');
      if (button instanceof HTMLButtonElement) {
        button.disabled = false;
        button.classList.remove("is-loading");
      }
      const status = form.querySelector("[data-save-status]");
      if (status) status.textContent = "";
    });
  });

  document.querySelectorAll("[data-bookmarklet]").forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      const status = document.querySelector("[data-bookmarklet-status]");
      if (status) status.textContent = "Drag this link to your bookmarks bar, then use it on an article page.";
    });
  });
})();

(() => {
  const clipboard = navigator.clipboard;

  if (clipboard && typeof clipboard.writeText === "function") {
    document.querySelectorAll("[data-copy-code]").forEach((button) => {
      button.hidden = false;
      button.addEventListener("click", async () => {
        const status = document.querySelector("[data-copy-status]");
        const originalLabel = button.textContent;

        try {
          await clipboard.writeText(button.dataset.copyCode || "");
          button.textContent = "Copied";
          if (status) status.textContent = "Code copied.";
          window.setTimeout(() => {
            button.textContent = originalLabel;
          }, 2000);
        } catch {
          if (status) status.textContent = "Copy failed. Select the code and copy it manually.";
        }
      });
    });
  }

  document.querySelectorAll("[data-save-form]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      const button = event.submitter;
      if (!(button instanceof HTMLButtonElement)) return;
      button.textContent = button.dataset.submitLabel || "Saving…";
      button.disabled = true;
    });
  });
})();

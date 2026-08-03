(() => {
  const analyticsContext = () => {
    const result = document.querySelector("[data-analytics-save-result]");
    if (result) return { path: "/result", title: "Saved article — Jot" };

    if (document.querySelector(".policy")) {
      return { path: "/privacy", title: "Privacy — Jot" };
    }

    const error = document.querySelector("[data-analytics-error-status]");
    if (error) {
      const status = /^\d{3}$/.test(error.dataset.analyticsErrorStatus || "")
        ? error.dataset.analyticsErrorStatus
        : "unknown";
      return { path: `/error/${status}`, title: "Error — Jot" };
    }

    return { path: "/", title: "Jot home" };
  };

  window.umamiBeforeSend = (_type, payload) => {
    if (!payload || typeof payload !== "object") return false;

    const { referrer: _referrer, data, ...sanitized } = payload;
    const context = analyticsContext();
    const allowedData = {};

    if (data && typeof data === "object") {
      if (["new", "existing"].includes(data.status)) allowedData.status = data.status;
      if (["short", "medium", "long"].includes(data.length)) allowedData.length = data.length;
      if (["success", "failure"].includes(data.result)) allowedData.result = data.result;
    }

    return {
      ...sanitized,
      url: context.path,
      title: context.title,
      ...(Object.keys(allowedData).length > 0 ? { data: allowedData } : {}),
    };
  };

  const trackEvent = (name, data) => {
    if (!window.umami || typeof window.umami.track !== "function") return;

    if (data && Object.keys(data).length > 0) {
      window.umami.track(name, data);
    } else {
      window.umami.track(name);
    }
  };

  const initialize = () => {
    const clipboard = navigator.clipboard;
    const result = document.querySelector("[data-analytics-save-result]");
    const length = result?.dataset.analyticsLength;

    if (window.umami && typeof window.umami.track === "function") {
      window.umami.track();
    }

    if (result) {
      trackEvent("article-saved", {
        status: result.dataset.analyticsSaveResult,
        length,
      });
    }

    if (clipboard && typeof clipboard.writeText === "function") {
      document.querySelectorAll("[data-copy-code]").forEach((button) => {
        button.hidden = false;
        button.addEventListener("click", async () => {
          const status = document.querySelector("[data-copy-status]");

          try {
            await clipboard.writeText(button.dataset.copyCode || "");
            if (status) status.textContent = "Code copied.";
            trackEvent("code-copy", { result: "success", length });
          } catch {
            if (status) status.textContent = "Copy failed. Select the code and copy it manually.";
            trackEvent("code-copy", { result: "failure", length });
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

    document.querySelectorAll("[data-analytics-article-open]").forEach((link) => {
      link.addEventListener("click", () => trackEvent("article-open", { length }));
    });

    document.querySelectorAll("[data-bookmarklet]").forEach((link) => {
      link.addEventListener("click", (event) => {
        event.preventDefault();
        const status = document.querySelector("[data-bookmarklet-status]");
        if (status) status.textContent = "Drag this link to your bookmarks bar, then use it on an article page.";
        trackEvent("bookmarklet-help");
      });
    });

    document.querySelectorAll("[data-analytics-ios-shortcut]").forEach((link) => {
      link.addEventListener("click", () => trackEvent("ios-shortcut-open"));
    });
  };

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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initialize, { once: true });
  } else {
    initialize();
  }
})();

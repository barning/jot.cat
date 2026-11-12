(function () {
	"use strict";

	const timers = new WeakMap();

	async function copyText(value) {
		if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
			try {
				await navigator.clipboard.writeText(value);
				return;
			} catch (error) {
				// Fall through to the selection-based copy method.
			}
		}

		const input = document.createElement("textarea");
		input.value = value;
		input.setAttribute("readonly", "");
		input.style.position = "fixed";
		input.style.opacity = "0";
		document.body.appendChild(input);
		input.select();
		input.setSelectionRange(0, value.length);

		let copied = false;
		try {
			copied = document.execCommand("copy");
		} finally {
			input.remove();
		}

		if (!copied) {
			throw new Error("Clipboard copy failed");
		}
	}

	function showStatus(button, message) {
		const statusId = button.getAttribute("aria-describedby");
		const status = statusId ? document.getElementById(statusId) : null;
		if (!status) {
			return;
		}

		const previousTimer = timers.get(status);
		if (previousTimer) {
			window.clearTimeout(previousTimer);
		}

		status.textContent = message;
		status.setAttribute("data-visible", "");
		timers.set(
			status,
			window.setTimeout(function () {
				status.removeAttribute("data-visible");
				status.textContent = "";
				timers.delete(status);
			}, 2000)
		);
	}

	document.addEventListener("click", async function (event) {
		if (!(event.target instanceof Element)) {
			return;
		}

		const button = event.target.closest("[data-jot-copy-url]");
		if (!(button instanceof HTMLButtonElement)) {
			return;
		}

		try {
			await copyText(button.dataset.jotCopyUrl || "");
			showStatus(button, button.dataset.jotCopySuccess || "Short link copied");
		} catch (error) {
			showStatus(button, button.dataset.jotCopyError || "Copy failed");
		}
	});
})();

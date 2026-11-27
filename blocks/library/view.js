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
		button.setAttribute("data-copied", "");
		timers.set(
			status,
			window.setTimeout(function () {
				status.removeAttribute("data-visible");
				button.removeAttribute("data-copied");
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
	// Progressive "Load more": without JavaScript the link opens the next cumulative page.
	// With it, the next page is fetched and only the new entries are appended in place.
	async function loadMore(link) {
		const library = link.closest(".jot-library");
		const list = library ? library.querySelector(".jot-library__entries") : null;
		if (!library || !list || link.hasAttribute("aria-busy")) {
			return;
		}

		const label = link.textContent;
		link.setAttribute("aria-busy", "true");
		link.textContent = link.dataset.jotLoading || label;

		try {
			const response = await fetch(link.href, { credentials: "same-origin" });
			if (!response.ok) {
				throw new Error("Request failed");
			}
			const doc = new DOMParser().parseFromString(await response.text(), "text/html");
			const libraries = Array.from(document.querySelectorAll(".jot-library"));
			const next = doc.querySelectorAll(".jot-library")[libraries.indexOf(library)];
			const nextList = next ? next.querySelector(".jot-library__entries") : null;
			if (!next || !nextList) {
				throw new Error("Library not found");
			}

			const added = Array.from(nextList.children).slice(list.children.length);
			added.forEach(function (item) {
				list.appendChild(document.adoptNode(item));
			});

			const more = library.querySelector(".jot-library__more");
			const nextMore = next.querySelector(".jot-library__more");
			if (more) {
				if (nextMore) {
					more.replaceWith(document.adoptNode(nextMore));
				} else {
					more.remove();
				}
			}

			const status = library.querySelector(".jot-library__count");
			if (status && link.dataset.jotLoaded) {
				status.textContent = link.dataset.jotLoaded.replace("%s", String(added.length));
			}
			const url = new URL(link.href);
			url.hash = "";
			window.history.replaceState(window.history.state, "", url);

			const focusTarget = added.length ? added[0].querySelector("h3 a") : null;
			if (focusTarget) {
				focusTarget.focus({ preventScroll: true });
				added[0].scrollIntoView({ block: "nearest", behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
			}
		} catch (error) {
			window.location.assign(link.href);
		}
	}

	document.addEventListener("click", function (event) {
		if (!(event.target instanceof Element) || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
			return;
		}
		const link = event.target.closest("a[data-jot-load-more]");
		if (link instanceof HTMLAnchorElement) {
			event.preventDefault();
			loadMore(link);
		}
	});
})();

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
	async function loadMore(link, automatic) {
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
					observe(nextMore.querySelector("a[data-jot-load-more]"));
				} else {
					more.remove();
				}
			}

			const announce = library.querySelector("[data-jot-announce]");
			if (announce && link.dataset.jotLoaded) {
				announce.textContent = link.dataset.jotLoaded.replace("%s", String(added.length));
			}
			const url = new URL(link.href);
			url.hash = "";
			window.history.replaceState(window.history.state, "", url);

			// Only an explicit click moves focus; automatic loading never pulls the reader away.
			const focusTarget = !automatic && added.length ? added[0].querySelector("h3 a") : null;
			if (focusTarget) {
				focusTarget.focus({ preventScroll: true });
				added[0].scrollIntoView({ block: "nearest", behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
			}
		} catch (error) {
			if (automatic) {
				link.removeAttribute("aria-busy");
				link.textContent = label;
			} else {
				window.location.assign(link.href);
			}
		}
	}

	// Lazy loading: fetch the next batch shortly before the reader reaches the end.
	// The link stays visible as a manual fallback and shows progress while loading.
	const observer = "IntersectionObserver" in window
		? new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						observer.unobserve(entry.target);
						loadMore(entry.target, true);
					}
				});
			},
			{ rootMargin: "0px 0px 600px 0px" }
		)
		: null;

	function observe(link) {
		if (observer && link) {
			observer.observe(link);
		}
	}

	// Topics beyond the first few fold behind a "+N more" chip.
	function setTopicsExpanded(button, expanded) {
		const list = button.closest("ul");
		if (!list) {
			return;
		}
		list.querySelectorAll("[data-jot-topic-extra]").forEach(function (item) {
			item.hidden = !expanded;
		});
		button.setAttribute("aria-expanded", expanded ? "true" : "false");
		button.textContent = expanded ? button.dataset.jotLess : button.dataset.jotMore;
	}

	function init() {
		document.querySelectorAll("[data-jot-topics-toggle]").forEach(function (button) {
			button.closest("li").hidden = false;
			setTopicsExpanded(button, false);
		});
		document.querySelectorAll(".jot-library a[data-jot-load-more]").forEach(observe);
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}

	document.addEventListener("click", function (event) {
		if (!(event.target instanceof Element) || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
			return;
		}
		const toggle = event.target.closest("[data-jot-topics-toggle]");
		if (toggle instanceof HTMLButtonElement) {
			setTopicsExpanded(toggle, toggle.getAttribute("aria-expanded") !== "true");
			return;
		}
		const link = event.target.closest("a[data-jot-load-more]");
		if (link instanceof HTMLAnchorElement) {
			event.preventDefault();
			if (observer) {
				observer.unobserve(link);
			}
			loadMore(link, false);
		}
	});
})();

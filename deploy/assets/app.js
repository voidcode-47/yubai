(() => {
  "use strict";

  const body = document.body;
  if (!body) return;

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const nav = document.querySelector(".nav");
  const progress = document.querySelector(".nav-progress");

  /* Reading progress and navigation background */

  let scrollQueued = false;
  const updateScrollUI = () => {
    const top = window.scrollY || document.documentElement.scrollTop;
    nav?.classList.toggle("is-scrolled", top > 12);
    if (progress) {
      const max = document.documentElement.scrollHeight - window.innerHeight;
      const ratio = max > 0 ? Math.min(1, Math.max(0, top / max)) : 0;
      progress.style.transform = `scaleX(${ratio})`;
    }
    scrollQueued = false;
  };

  window.addEventListener("scroll", () => {
    if (!scrollQueued) {
      scrollQueued = true;
      window.requestAnimationFrame(updateScrollUI);
    }
  }, { passive: true });
  updateScrollUI();

  /* Section rules draw in when they enter the viewport */

  const heads = document.querySelectorAll(".section-head");
  if ("IntersectionObserver" in window && !reduceMotion) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-in");
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.2 });
    heads.forEach((head) => observer.observe(head));
  } else {
    heads.forEach((head) => head.classList.add("is-in"));
  }

  /* Countdown to the next drop */

  const clock = document.getElementById("next-clock");
  const nextDate = document.getElementById("next-date");
  const DAY = 24 * 60 * 60 * 1000;
  const OFFSET = 8 * 60 * 60 * 1000;
  const pad = (value) => String(value).padStart(2, "0");
  const weekday = ["周日", "周一", "周二", "周三", "周四", "周五", "周六"];

  const data = body.dataset;
  const issueDate = data.issueDate || "";
  const isLatest = data.isLatest === "1";
  const initiallyDegraded = data.degraded === "1";
  const refreshUrl = data.refreshUrl === "" ? "" : (data.refreshUrl || "refresh.php?auto=1");
  const dropHour = Number(data.dropHour || 9);

  const computeNextDrop = () => {
    const now = Date.now();
    const beijing = new Date(now + OFFSET);
    let target = Date.UTC(
      beijing.getUTCFullYear(),
      beijing.getUTCMonth(),
      beijing.getUTCDate(),
      dropHour - 8, 0, 0, 0
    );
    if (target <= now) target += DAY;
    return target;
  };

  let target = Number(data.nextDrop || 0) * 1000;
  if (!Number.isFinite(target) || target <= 0) {
    target = computeNextDrop();
  }
  let dropHandled = false;

  const renderCountdown = () => {
    if (!clock) return;
    const remaining = Math.max(0, target - Date.now());
    const hours = Math.floor(remaining / 3600000);
    const minutes = Math.floor((remaining % 3600000) / 60000);
    const seconds = Math.floor((remaining % 60000) / 1000);
    clock.textContent = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;

    if (nextDate) {
      const beijing = new Date(target + OFFSET);
      nextDate.textContent = `${pad(beijing.getUTCMonth() + 1)}.${pad(beijing.getUTCDate())} ${weekday[beijing.getUTCDay()]} / ${pad(dropHour)}:00`;
    }
  };

  const updateCountdown = () => {
    const now = Date.now();
    if (now >= target) {
      while (target <= now) target += DAY;
      renderCountdown();
      if (!dropHandled) {
        dropHandled = true;
        onDropArrived();
      }
      return;
    }
    renderCountdown();
  };

  /* Toast */

  const toast = document.getElementById("toast");
  let toastTimer = 0;

  const hideToast = () => {
    if (toast) toast.classList.remove("is-on");
  };

  const openToast = (seconds) => {
    if (!toast) return;
    toast.classList.add("is-on");
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(hideToast, seconds * 1000);
  };

  const showRandomToast = (title, url) => {
    if (!toast) return;
    toast.replaceChildren();
    toast.append(document.createTextNode("随手翻到 "));
    const link = document.createElement("a");
    link.href = url;
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    link.textContent = title || url;
    toast.append(link, document.createTextNode(" ↗"));
    openToast(6);
  };

  const showUpdateToast = (message) => {
    if (!toast) return;
    toast.replaceChildren();
    toast.append(document.createTextNode(message + " "));
    const link = document.createElement("a");
    link.href = "./";
    link.textContent = "刷新看看";
    link.addEventListener("click", (event) => {
      event.preventDefault();
      window.location.reload();
    });
    toast.append(link);
    openToast(14);
  };

  /* Background refresh: only current issue, only when something is missing */

  let refreshing = false;
  let lastRefreshCheck = 0;
  const REFRESH_MIN_GAP = 10 * 60 * 1000;

  const requestRefresh = async (fromDrop = false) => {
    if (!refreshUrl || !isLatest || !window.fetch || refreshing) return;
    if (document.visibilityState === "hidden") return;
    if (!fromDrop && Date.now() - lastRefreshCheck < REFRESH_MIN_GAP) return;

    refreshing = true;
    lastRefreshCheck = Date.now();
    try {
      const url = refreshUrl + (refreshUrl.includes("?") ? "&" : "?") + "_=" + Date.now();
      const response = await fetch(url, {
        cache: "no-store",
        credentials: "same-origin",
        headers: { "Accept": "application/json" }
      });
      if (!response.ok) return;
      const result = await response.json();
      if (!result || result.ok === false) return;

      if (result.built) {
        if (result.date && result.date !== issueDate) {
          showUpdateToast("新的一期已经更新。");
        } else if (initiallyDegraded && result.degraded === false) {
          showUpdateToast("本期缺失的内容已经补齐。");
        }
      } else if (fromDrop) {
        showUpdateToast("九点到了，新的一期可能已经就绪。");
      }
    } catch (error) {
      // 离线或主机暂时不可达时保持安静，下一次再试。
    } finally {
      refreshing = false;
    }
  };

  function onDropArrived() {
    if (!isLatest) return;
    window.setTimeout(() => requestRefresh(true), 1200);
  }

  updateCountdown();
  window.setInterval(updateCountdown, 1000);

  if (isLatest) {
    window.setTimeout(() => requestRefresh(false), 1800);
    window.setInterval(() => {
      if (!document.hidden) requestRefresh(false);
    }, 20 * 60 * 1000);
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) requestRefresh(false);
    });
  }

  /* Category filter: only the project cards, never the news feed */

  const filterChips = Array.from(document.querySelectorAll(".cat-chip[data-filter]"));
  const filterPicks = Array.from(document.querySelectorAll(".pick[data-cat]"));
  const filterGroups = Array.from(document.querySelectorAll(".pick-cat[data-cat]"));
  const validFilters = new Set(filterChips.map((chip) => chip.dataset.filter || "all"));

  const readHashFilter = () => {
    const match = /(?:^#|&)cat=([a-z0-9_-]+)/i.exec(window.location.hash || "");
    const value = match ? match[1] : "all";
    return validFilters.has(value) ? value : "all";
  };

  const writeHashFilter = (value) => {
    const rest = (window.location.hash || "")
      .replace(/(?:^#|&)cat=[a-z0-9_-]+/i, "")
      .replace(/^&/, "");
    const hash = value === "all"
      ? (rest ? "#" + rest : "")
      : "#cat=" + value + (rest ? "&" + rest : "");
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, "", window.location.pathname + window.location.search + hash);
    }
  };

  const applyCategoryFilter = (value) => {
    filterChips.forEach((chip) => {
      chip.setAttribute("aria-pressed", chip.dataset.filter === value ? "true" : "false");
    });
    filterPicks.forEach((pick) => {
      pick.hidden = value !== "all" && pick.dataset.cat !== value;
    });
    filterGroups.forEach((group) => {
      group.hidden = value !== "all" && group.dataset.cat !== value;
    });
  };

  if (filterChips.length && filterPicks.length) {
    filterChips.forEach((chip) => {
      chip.addEventListener("click", () => {
        const value = chip.dataset.filter || "all";
        const isOn = chip.getAttribute("aria-pressed") === "true";
        // 再点一次当前分类就回到「全部」，避免按下去没有反应。
        const next = isOn && value !== "all" ? "all" : value;
        applyCategoryFilter(next);
        writeHashFilter(next);
      });
    });

    // 分享 #cat=build 时，打开就是筛选后的状态；浏览器前进后退也跟着变。
    if (/(?:^#|&)cat=/i.test(window.location.hash || "")) {
      applyCategoryFilter(readHashFilter());
    } else {
      applyCategoryFilter("all");
    }
    window.addEventListener("hashchange", () => {
      if (/(?:^#|&)cat=/i.test(window.location.hash || "")) {
        applyCategoryFilter(readHashFilter());
      }
    });
  }

  /* Whole card opens the original site; the title link and discussion link stay real links */

  document.querySelectorAll(".pick[data-href]").forEach((card) => {
    card.addEventListener("click", (event) => {
      if (event.target.closest("a, button, input, label, select, textarea")) return;
      const selection = window.getSelection();
      if (selection && selection.toString().length) return;
      const href = card.dataset.href || "";
      if (!href) return;
      window.open(href, "_blank", "noopener,noreferrer");
    });
  });

  /* Archive keyword filter: plain substring match, no index to maintain */

  const archiveFilter = document.getElementById("archive-filter");
  const archiveRows = Array.from(document.querySelectorAll(".archive-row"));
  const archiveFilterEmpty = document.getElementById("archive-filter-empty");
  const archiveFilterCount = document.getElementById("archive-filter-count");
  const archiveCurrent = document.querySelector(".archive-current");
  const archiveFirstEmpty = document.querySelector(".archive-list > .archive-empty:not(#archive-filter-empty)");

  const applyArchiveFilter = () => {
    if (!archiveFilter) return;
    const query = archiveFilter.value.trim().toLowerCase();
    let shown = 0;
    archiveRows.forEach((row) => {
      const hit = query === "" || (row.dataset.keywords || "").toLowerCase().includes(query);
      row.hidden = !hit;
      if (hit) shown++;
    });
    if (archiveCurrent) archiveCurrent.hidden = query !== "";
    if (archiveFirstEmpty) archiveFirstEmpty.hidden = query !== "";
    if (archiveFilterEmpty) archiveFilterEmpty.hidden = query === "" || shown > 0;
    if (archiveFilterCount) {
      archiveFilterCount.textContent = query === "" ? "" : shown + " 期匹配";
    }
  };

  if (archiveFilter && archiveRows.length) {
    archiveFilter.addEventListener("input", applyArchiveFilter);
    archiveFilter.addEventListener("search", applyArchiveFilter);
  }

  /* A title link inside <summary> opens the issue instead of toggling the row */

  document.querySelectorAll(".archive-row summary a").forEach((link) => {
    link.addEventListener("click", (event) => event.stopPropagation());
  });

  /* Random pick from every published project link */

  const randomButton = document.querySelector("[data-random-pick]");
  const seen = new Set();
  const randomLinks = Array.from(document.querySelectorAll("a[data-random]")).filter((link) => {
    if (!link.href || seen.has(link.href)) return false;
    seen.add(link.href);
    return true;
  });

  let lastRandom = "";

  randomButton?.addEventListener("click", () => {
    if (!randomLinks.length) {
      showUpdateToast("今天还没有可以翻的链接。");
      return;
    }
    // 只从当前筛选后仍可见的项目里抽；没有可见项目时退回全部。
    const visibleLinks = randomLinks.filter((link) => {
      const card = link.closest(".pick");
      return !card || !card.hidden;
    });
    const source = visibleLinks.length ? visibleLinks : randomLinks;
    const pool = source.filter((link) => link.href !== lastRandom);
    const chosen = (pool.length ? pool : source)[Math.floor(Math.random() * (pool.length || source.length))];
    lastRandom = chosen.href;

    const title = chosen.dataset.title || chosen.textContent.trim().split("\n")[0].trim();
    window.open(chosen.href, "_blank", "noopener,noreferrer");
    showRandomToast(title, chosen.href);

    randomButton.classList.add("is-rolling");
    window.setTimeout(() => randomButton.classList.remove("is-rolling"), 700);
  });

  // 键盘也能随手翻：不在输入框里时按 R 即可。
  document.addEventListener("keydown", (event) => {
    if (event.key !== "r" && event.key !== "R") return;
    if (event.metaKey || event.ctrlKey || event.altKey) return;
    const target = event.target;
    const tag = target && target.tagName ? target.tagName.toLowerCase() : "";
    if (tag === "input" || tag === "textarea" || tag === "select" || (target && target.isContentEditable)) return;
    if (!randomButton) return;
    event.preventDefault();
    randomButton.click();
  });
})();

/* global PetePSC */
/**
 * Pete Panel Site Converter - Admin Export UI (no jQuery)
 *
 * Expects:
 * window.PetePSC = { nonce, startUrl, restRoot, i18n }
 */
(function () {
  "use strict";

  const $ = (sel) => document.querySelector(sel);

  const btn = $("#pete-start-export");
  const progressWrap = $("#pete-progress");
  const barFill = $("#pete-progress-fill");
  const progressText = $("#pete-progress-text");
  const downloadWrap = $("#pete-download");

  if (!btn || !progressWrap || !barFill || !progressText || !downloadWrap) {
    return;
  }

  const i18n = (window.PetePSC && window.PetePSC.i18n) ? window.PetePSC.i18n : {};
  const t = (k, fallback) => (i18n && i18n[k]) ? i18n[k] : (fallback || k);

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  function setProgress(pct, msg) {
    const safe = Math.max(0, Math.min(100, parseInt(pct || 0, 10)));
    barFill.style.width = safe + "%";
    progressText.textContent = msg || "";

    // Only show when there is actual work to show
    if (window.getComputedStyle(progressWrap).display === "none") {
      progressWrap.style.display = "block";
    }
  }

  function hideProgress() {
    progressWrap.style.display = "none";
  }

  function clearDownload() {
    downloadWrap.innerHTML = "";
  }

  function setDownload(href, label, locationLabel) {
    downloadWrap.innerHTML = "";

    if (locationLabel) {
      const p = document.createElement("p");
      p.className = "pete-psc-location";
      p.textContent = locationLabel;
      downloadWrap.appendChild(p);
    }

    const a = document.createElement("a");
    a.href = href;
    a.className = "button button-primary";
    a.textContent = label || t("download_default", "Download export");
    downloadWrap.appendChild(a);
  }

  function setError(msg) {
    const div = document.createElement("div");
    div.className = "pete-psc-error";
    div.textContent = msg;

    downloadWrap.innerHTML = "";
    downloadWrap.appendChild(div);
  }

  async function restJson(url, opts) {
    const headers = Object.assign(
      {
        "Accept": "application/json",
        "X-WP-Nonce": (window.PetePSC && window.PetePSC.nonce) ? window.PetePSC.nonce : "",
      },
      (opts && opts.headers) ? opts.headers : {}
    );

    const res = await fetch(url, Object.assign({}, opts || {}, { headers }));
    const raw = await res.text();

    let data = null;
    if (raw) {
      try {
        data = JSON.parse(raw);
      } catch (e) {
        data = null;
      }
    }

    if (!res.ok) {
      const msg =
        (data && (data.message || data.error)) ||
        (raw && raw.slice(0, 200)) ||
        ("HTTP " + res.status);
      const err = new Error(msg);
      err.status = res.status;
      err.data = data;
      throw err;
    }

    return data;
  }

  function buildStatusUrl(jobId) {
    // startUrl is .../wp-json/pete/v1/export
    return (window.PetePSC.startUrl || "") + "/" + encodeURIComponent(jobId) + "/status";
  }

  function buildRunUrl(jobId) {
    return (window.PetePSC.startUrl || "") + "/" + encodeURIComponent(jobId) + "/run";
  }

  async function startExport() {
    clearDownload();
    hideProgress();

    btn.disabled = true;
    btn.textContent = t("starting", "Starting…");

    setProgress(1, t("queued", "Queued…"));

    let startRes = null;
    try {
      startRes = await restJson(window.PetePSC.startUrl, {
        method: "POST",
        credentials: "same-origin",
      });
    } catch (e) {
      btn.disabled = false;
      btn.textContent = t("start", "Start export");
      setError(t("failed_start", "Failed to start:") + " " + (e && e.message ? e.message : "Unknown error"));
      return;
    }

    const jobId = startRes && startRes.job ? startRes.job : null;
    if (!jobId) {
      btn.disabled = false;
      btn.textContent = t("start", "Start export");
      setError(t("failed_start", "Failed to start:") + " Missing job id.");
      return;
    }

    btn.textContent = t("working", "Working…");

    await pollUntilDone(jobId);

    btn.disabled = false;
    btn.textContent = t("start", "Start export");
  }

  async function pollUntilDone(jobId) {
    const statusUrl = buildStatusUrl(jobId);
    const runUrl = buildRunUrl(jobId);

    let lastProgress = -1;
    let lastMessage = "";
    let unchangedTicks = 0;
    let forceRunTriggered = false;

    // If cron is blocked, we’ll force-run after a short stall.
    const FORCE_RUN_AFTER_TICKS = 12; // ~12 seconds if interval is 1s initially
    const MAX_TICKS = 60 * 30; // hard safety cap: ~30 minutes worst-case
    let tick = 0;

    while (tick < MAX_TICKS) {
      tick++;

      let state = null;
      try {
        state = await restJson(statusUrl, {
          method: "GET",
          credentials: "same-origin",
        });
      } catch (e) {
        // transient hiccup: keep trying
        setProgress(
          Math.max(1, lastProgress > 0 ? lastProgress : 1),
          t("working", "Working…") + " " + (e && e.message ? "(" + e.message + ")" : "")
        );
        await sleep(1500);
        continue;
      }

      const pct = state && typeof state.progress !== "undefined" ? parseInt(state.progress, 10) : 0;
      const msg = state && state.message ? String(state.message) : t("working", "Working…");

      setProgress(pct, msg);

      // detect stall
      if (pct === lastProgress && msg === lastMessage) {
        unchangedTicks++;
      } else {
        unchangedTicks = 0;
        lastProgress = pct;
        lastMessage = msg;
      }

      // if stalled early, trigger force-run once
      if (!forceRunTriggered && unchangedTicks >= FORCE_RUN_AFTER_TICKS && !state.done) {
        forceRunTriggered = true;
        setProgress(Math.max(3, pct), t("cron_blocked", "Cron seems blocked. Running export directly…"));
        try {
          await restJson(runUrl, {
            method: "POST",
            credentials: "same-origin",
          });
        } catch (e) {
          // even if force-run call fails, keep polling (cron may still work)
        }
      }

      if (state && state.done) {
        if (state.error) {
          setProgress(100, t("export_failed", "Export failed:") + " " + String(state.error));
          setError(t("export_failed", "Export failed:") + " " + String(state.error));
          return;
        }

        if (state.download) {
          const locationLabel = state.zip_location_label ? String(state.zip_location_label) : "";
          const label = state.download_name ? String(state.download_name) : t("download_default", "Download export");
          setProgress(100, t("ready", "Export ready."));
          setDownload(String(state.download), label, locationLabel);
          return;
        }

        setProgress(100, t("download_fallback", "Export finished, but download link is missing. Please refresh this page."));
        setError(t("download_fallback", "Export finished, but download link is missing. Please refresh this page."));
        return;
      }

      // polling interval: quicker early, slower later
      const interval = pct < 10 ? 1000 : 2000;
      await sleep(interval);
    }

    setError(t("error_prefix", "Error:") + " Polling timed out.");
  }

  btn.addEventListener("click", function (e) {
    e.preventDefault();
    startExport();
  });
})();
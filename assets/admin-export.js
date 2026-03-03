/* global PetePSC */
/**
 * Pete Panel Site Converter - Admin Export UI (no jQuery)
 *
 * This file expects wp_localize_script() to provide:
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
    if (progressWrap.style.display === "none") {
      progressWrap.style.display = "block";
    }
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
    div.style.marginTop = "10px";
    div.style.padding = "10px 12px";
    div.style.border = "1px solid #d63638";
    div.style.background = "#fcf0f1";
    div.style.borderRadius = "4px";
    div.style.maxWidth = "820px";
    div.style.color = "#1d2327";
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

    // Read text first, then parse JSON if possible (handles empty bodies / HTML)
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
        (res.status + " " + res.statusText) ||
        "Request failed";
      const err = new Error(msg);
      err.status = res.status;
      err.data = data;
      err.raw = raw;
      throw err;
    }

    return data;
  }

  function restRoot() {
    const root = (window.PetePSC && window.PetePSC.restRoot) ? window.PetePSC.restRoot : "/wp-json/";
    return root.endsWith("/") ? root : (root + "/");
  }

  function makeStatusUrl(jobId) {
    return restRoot() + "pete/v1/export/" + encodeURIComponent(jobId) + "/status";
  }

  function makeRunUrl(jobId) {
    return restRoot() + "pete/v1/export/" + encodeURIComponent(jobId) + "/run";
  }

  // Polling + stall detection
  const POLL_MS = 2500;
  const STALL_MS = 45000; // 45s without any progress change -> attempt force-run.
  const MIN_POLLS_BEFORE_FORCE = 6;
  const FORCE_RUN_COOLDOWN_MS = 60000;

  async function maybeForceRun(jobId, forceRunAttemptedAt) {
    const now = Date.now();
    if (forceRunAttemptedAt && (now - forceRunAttemptedAt) < FORCE_RUN_COOLDOWN_MS) {
      return forceRunAttemptedAt;
    }

    try {
      setProgress(Math.max(parseInt(barFill.style.width, 10) || 3, 3), t("cron_blocked", "Cron seems blocked. Running export directly…"));
      await restJson(makeRunUrl(jobId), { method: "POST" });
      return now;
    } catch (e) {
      // If force-run fails, keep polling; status endpoint will show error eventually.
      return forceRunAttemptedAt;
    }
  }

  async function pollJob(jobId) {
    let lastPct = -1;
    let lastChangeAt = Date.now();
    let polls = 0;
    let forceRunAttemptedAt = 0;

    while (true) {
      polls += 1;

      let st;
      try {
        st = await restJson(makeStatusUrl(jobId), { method: "GET" });
      } catch (err) {
        // transient errors: keep going a bit
        const shownPct = Math.max(lastPct, 5);
        setProgress(shownPct, t("error_prefix", "Error:") + " " + err.message);
        await sleep(POLL_MS);
        continue;
      }

      const pct = (st && typeof st.progress !== "undefined") ? parseInt(st.progress, 10) : 0;
      const msg = (st && st.message) ? String(st.message) : "";

      if (!Number.isNaN(pct) && pct !== lastPct) {
        lastPct = pct;
        lastChangeAt = Date.now();
      }

      setProgress(Number.isNaN(pct) ? Math.max(lastPct, 5) : pct, msg || t("working", "Working…"));

      // If done, render download or error.
      if (st && st.done) {
        if (st.error) {
          setError(t("export_failed", "Export failed:") + " " + String(st.error));
          btn.disabled = false;
          btn.textContent = t("start", "Start export");
          return;
        }

        // The status endpoint in PHP sets `download` as nonce-protected URL.
        if (st.download) {
          setDownload(
            String(st.download),
            st.download_name || t("download_default", "Download export"),
            st.zip_location_label ? String(st.zip_location_label) : ""
          );
        } else {
          setError(t("download_fallback", "Export finished, but download link is missing. Please refresh this page."));
        }

        btn.disabled = false;
        btn.textContent = t("start", "Start export");
        return;
      }

      // Stall detection -> force-run fallback
      const stalled = (Date.now() - lastChangeAt) > STALL_MS;
      if (stalled && polls >= MIN_POLLS_BEFORE_FORCE) {
        forceRunAttemptedAt = await maybeForceRun(jobId, forceRunAttemptedAt);
        // Reset stall timer slightly to avoid tight loops
        lastChangeAt = Date.now();
      }

      await sleep(POLL_MS);
    }
  }

  async function startExport() {
    clearDownload();
    setProgress(2, t("starting", "Starting…"));

    btn.disabled = true;
    btn.textContent = t("starting", "Starting…");

    const startUrl = (window.PetePSC && window.PetePSC.startUrl) ? window.PetePSC.startUrl : "";

    if (!startUrl) {
      setError(t("failed_start", "Failed to start:") + " Missing startUrl");
      btn.disabled = false;
      btn.textContent = t("start", "Start export");
      return;
    }

    try {
      const data = await restJson(startUrl, { method: "POST" });
      const jobId = data && data.job ? String(data.job) : "";

      if (!jobId) {
        throw new Error("Missing job id in response");
      }

      setProgress(5, t("queued", "Queued…"));
      await pollJob(jobId);
    } catch (err) {
      setError(t("failed_start", "Failed to start:") + " " + err.message);
      btn.disabled = false;
      btn.textContent = t("start", "Start export");
    }
  }

  btn.addEventListener("click", function (e) {
    e.preventDefault();
    startExport();
  });

})();
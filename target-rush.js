(() => {
  const status = document.querySelector("[data-rush-status]");
  const attempts = document.querySelector("[data-rush-attempts]");
  const goal = document.querySelector("[data-rush-goal]");
  const rewardNode = document.querySelector("[data-rush-reward]");
  const startButton = document.querySelector("[data-rush-start]");
  const targetButton = document.querySelector("[data-rush-target]");
  const screens = [...document.querySelectorAll("[data-rush-screen]")];
  if ([status, attempts, goal, rewardNode, startButton, targetButton].some((node) => !(node instanceof HTMLElement))) return;
  const previewMode = new URLSearchParams(window.location.search).get("preview") === "1";
  const previewDurationMs = 30_000;
  const previewTargetIntervalMs = 800;

  const positions = {
    "upper-left": [46, 31],
    "upper-right": [62, 35],
    "middle-left": [52, 54],
    "middle-right": [71, 57],
    "lower-left": [43, 72],
    "lower-right": [87, 58],
  };
  let csrfToken = "";
  let session = null;
  let rewardAmount = 0;
  let attemptsRemaining = 0;
  let serverOffsetMs = 0;
  let pollTimer = 0;
  let clockTimer = 0;
  let pending = false;
  let finishing = false;
  let previewState = null;
  let previewTimer = 0;
  let previewFallbackAvailable = false;

  const api = async (path, method = "GET", data = null) => {
    const headers = { Accept: "application/json" };
    const options = { method, credentials: "same-origin", cache: "no-store", headers };
    if (data !== null) {
      if (!csrfToken) csrfToken = (await api("/api/auth/csrf")).csrf_token;
      headers["Content-Type"] = "application/json";
      headers["X-CSRF-Token"] = csrfToken;
      options.body = JSON.stringify(data);
    }
    const response = await fetch(path, options);
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("The game service returned an unreadable response.");
    }
    if (!response.ok || result.success !== true) {
      const error = new Error(result.errors?.[0]?.message || "The game request could not be completed.");
      error.code = result.errors?.[0]?.code;
      throw error;
    }
    return result.data;
  };

  const setStatus = (message, isError = false) => {
    status.textContent = message;
    status.classList.toggle("is-error", isError);
  };

  const showScreen = (name) => {
    for (const screen of screens) {
      const visible = screen.dataset.rushScreen === name;
      screen.hidden = !visible;
      screen.classList.toggle("is-visible", visible);
    }
  };

  const formatSeconds = (milliseconds) => {
    const seconds = Math.max(0, Math.ceil(milliseconds / 1000));
    return `00:${String(seconds).padStart(2, "0")}`;
  };

  const renderResults = (current) => {
    document.querySelectorAll("[data-rush-final-score]").forEach((node) => {
      node.textContent = new Intl.NumberFormat().format(current.score);
    });
    document.querySelectorAll("[data-rush-best-combo]").forEach((node) => {
      node.textContent = `x${new Intl.NumberFormat().format(current.best_combo)}`;
    });
    const finalReward = document.querySelector("[data-rush-final-reward]");
    if (finalReward instanceof HTMLElement) {
      finalReward.textContent = `${new Intl.NumberFormat().format(rewardAmount)}`;
    }
  };

  const finishPreviewRound = () => {
    if (!previewState || !previewState.active) return;
    previewState.active = false;
    targetButton.hidden = true;
    window.clearInterval(previewTimer);
    renderResults({ score: previewState.score, best_combo: previewState.bestCombo });
    const finalReward = document.querySelector("[data-rush-final-reward]");
    if (finalReward instanceof HTMLElement && finalReward.parentElement) {
      finalReward.parentElement.textContent = "Reward: none (preview)";
    }
    const won = previewState.score >= Number(goal.textContent.replaceAll(",", ""));
    showScreen(won ? "victory" : "game-over");
    setStatus("Preview finished. Your score was not saved and no Earned Coins were awarded.");
  };

  const updatePreviewRound = () => {
    if (!previewState || !previewState.active) return;
    const elapsed = Date.now() - previewState.startedAt;
    const remaining = Math.max(0, previewDurationMs - elapsed);
    const time = document.querySelector("[data-rush-time]");
    if (time instanceof HTMLElement) time.textContent = formatSeconds(remaining);
    if (remaining === 0) {
      finishPreviewRound();
      return;
    }

    const index = Math.floor(elapsed / previewTargetIntervalMs);
    if (index === previewState.targetIndex) return;
    previewState.targetIndex = index;
    const target = previewState.targets[index];
    if (!target) {
      targetButton.hidden = true;
      return;
    }
    const [x, y] = positions[target.position];
    targetButton.style.left = `${x}%`;
    targetButton.style.top = `${y}%`;
    targetButton.dataset.kind = target.type;
    targetButton.dataset.previewIndex = String(index);
    targetButton.setAttribute(
      "aria-label",
      target.type === "gold" ? "Hit gold bonus target" : target.type === "danger" ? "Avoid danger target" : "Hit normal target",
    );
    targetButton.disabled = false;
    targetButton.hidden = false;
  };

  const startPreviewRound = () => {
    window.clearInterval(previewTimer);
    const targetTypes = Array.from({ length: 38 }, () => {
      const roll = Math.random();
      return roll <= 0.55 ? "normal" : roll <= 0.8 ? "gold" : "danger";
    });
    const positionsByType = {
      normal: ["upper-left", "middle-left"],
      gold: ["upper-right", "middle-right", "lower-left"],
      danger: ["lower-right"],
    };
    previewState = {
      active: true,
      startedAt: Date.now(),
      targetIndex: -1,
      lastHitIndex: null,
      score: 0,
      combo: 0,
      bestCombo: 0,
      targets: targetTypes.map((type) => {
        const available = positionsByType[type];
        return {
          type,
          position: available[Math.floor(Math.random() * available.length)],
        };
      }),
    };
    const score = document.querySelector("[data-rush-score]");
    const combo = document.querySelector("[data-rush-combo]");
    if (score instanceof HTMLElement) score.textContent = "0";
    if (combo instanceof HTMLElement) combo.textContent = "x0";
    startButton.disabled = true;
    showScreen("game");
    setStatus("Preview only: scores are not saved and no Earned Coins can be won.");
    updatePreviewRound();
    previewTimer = window.setInterval(updatePreviewRound, 100);
  };

  const hitPreviewTarget = () => {
    if (!previewState?.active) return;
    const elapsed = Date.now() - previewState.startedAt;
    if (elapsed >= previewDurationMs) {
      finishPreviewRound();
      return;
    }
    const index = Math.floor(elapsed / previewTargetIntervalMs);
    if (
      index !== previewState.targetIndex
      || targetButton.dataset.previewIndex !== String(index)
      || targetButton.hidden
    ) return;

    const targetType = targetButton.dataset.kind;
    targetButton.hidden = true;
    if (previewState.lastHitIndex !== null && index !== previewState.lastHitIndex + 1) {
      previewState.combo = 0;
    }
    if (targetType === "danger") {
      previewState.score = Math.max(0, previewState.score - 15);
      previewState.combo = 0;
      setStatus("Danger hit! Avoid red targets.");
    } else {
      previewState.combo++;
      previewState.bestCombo = Math.max(previewState.bestCombo, previewState.combo);
      const bonus = Math.min(5, previewState.combo - 1);
      const points = targetType === "gold" ? 20 + (bonus * 3) : 10 + (bonus * 2);
      previewState.score += points;
      setStatus(`Target hit! +${points} points. Preview scores are not saved.`);
    }
    previewState.lastHitIndex = index;
    const score = document.querySelector("[data-rush-score]");
    const combo = document.querySelector("[data-rush-combo]");
    if (score instanceof HTMLElement) {
      score.textContent = new Intl.NumberFormat().format(previewState.score);
    }
    if (combo instanceof HTMLElement) {
      combo.textContent = `x${new Intl.NumberFormat().format(previewState.combo)}`;
    }
  };

  const scheduleRefresh = (current) => {
    window.clearTimeout(pollTimer);
    if (current.status === "completed" || current.status === "failed" || current.time_remaining_ms <= 0) return;
    const delay = Math.max(
      100,
      current.next_target_at_ms - (Date.now() + serverOffsetMs) + 35,
    );
    pollTimer = window.setTimeout(() => refreshSession(), delay);
  };

  const updateClock = () => {
    if (!session || !["started", "in_progress"].includes(session.status)) return;
    const remaining = Math.max(
      0,
      session.time_remaining_ms - (Date.now() + serverOffsetMs - session.server_time_ms),
    );
    const time = document.querySelector("[data-rush-time]");
    if (time instanceof HTMLElement) time.textContent = formatSeconds(remaining);
    if (remaining === 0) finishRound();
  };

  const renderSession = (current) => {
    session = current;
    if (!current) {
      targetButton.hidden = true;
      showScreen("start");
      startButton.disabled = attemptsRemaining <= 0;
      return;
    }
    serverOffsetMs = current.server_time_ms - Date.now();
    const score = document.querySelector("[data-rush-score]");
    const combo = document.querySelector("[data-rush-combo]");
    const time = document.querySelector("[data-rush-time]");
    if (score instanceof HTMLElement) score.textContent = new Intl.NumberFormat().format(current.score);
    if (combo instanceof HTMLElement) combo.textContent = `x${new Intl.NumberFormat().format(current.combo)}`;
    if (time instanceof HTMLElement) time.textContent = formatSeconds(current.time_remaining_ms);

    if (current.status === "completed") {
      targetButton.hidden = true;
      renderResults(current);
      showScreen("victory");
      setStatus(`Target Rush cleared! ${new Intl.NumberFormat().format(rewardAmount)} Earned Coins were credited.`);
      window.clearTimeout(pollTimer);
      window.clearInterval(clockTimer);
      return;
    }
    if (current.status === "failed") {
      targetButton.hidden = true;
      renderResults(current);
      showScreen("game-over");
      setStatus(`Round over. Reach ${new Intl.NumberFormat().format(Number(goal.textContent.replaceAll(",", "")))} points next time.`);
      window.clearTimeout(pollTimer);
      window.clearInterval(clockTimer);
      return;
    }

    showScreen("game");
    const active = current.active_target;
    if (active && positions[active.position]) {
      const [x, y] = positions[active.position];
      targetButton.style.left = `${x}%`;
      targetButton.style.top = `${y}%`;
      targetButton.dataset.kind = active.type;
      targetButton.dataset.token = active.token;
      targetButton.setAttribute(
        "aria-label",
        active.type === "gold" ? "Hit gold bonus target" : active.type === "danger" ? "Avoid danger target" : "Hit normal target",
      );
      targetButton.hidden = false;
      targetButton.disabled = pending;
    } else {
      targetButton.hidden = true;
      targetButton.removeAttribute("data-token");
    }
    if (current.time_remaining_ms > 0) {
      if (!clockTimer) clockTimer = window.setInterval(updateClock, 100);
      scheduleRefresh(current);
    }
  };

  const refreshSession = async () => {
    if (!session || pending) return;
    try {
      const result = await api(`/api/games/target-rush/sessions/${session.id}`);
      renderSession(result.session);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "The game state could not be refreshed.", true);
      window.clearTimeout(pollTimer);
      pollTimer = window.setTimeout(refreshSession, 1000);
    }
  };

  const finishRound = async () => {
    if (!session || pending || finishing || !["started", "in_progress"].includes(session.status)) return;
    finishing = true;
    pending = true;
    targetButton.disabled = true;
    try {
      const result = await api(`/api/games/target-rush/sessions/${session.id}/finish`, "POST", {});
      attemptsRemaining = result.attempts_remaining_today;
      attempts.textContent = String(attemptsRemaining);
      rewardAmount = result.reward?.amount ?? rewardAmount;
      renderSession(result.session);
    } catch (error) {
      if (error.code === "GAME_ROUND_ACTIVE") {
        finishing = false;
        return;
      }
      setStatus(error instanceof Error ? error.message : "The round result could not be verified.", true);
      window.setTimeout(() => {
        finishing = false;
        finishRound();
      }, 1000);
    } finally {
      pending = false;
      targetButton.disabled = false;
      if (session?.status === "completed" || session?.status === "failed") {
        window.clearInterval(clockTimer);
      }
    }
  };

  const startRound = async () => {
    if (previewMode) {
      if (!pending) startPreviewRound();
      return;
    }
    if (previewFallbackAvailable) {
      window.location.assign(`${window.location.pathname}?preview=1`);
      return;
    }
    if (pending || attemptsRemaining <= 0) return;
    pending = true;
    startButton.disabled = true;
    try {
      const result = await api("/api/games/target-rush/sessions", "POST", {
        idempotency_key: crypto.randomUUID(),
      });
      attemptsRemaining = result.attempts_remaining_today;
      attempts.textContent = String(attemptsRemaining);
      rewardAmount = result.reward_amount_coins;
      finishing = false;
      renderSession(result.session);
      setStatus("Hit normal and gold targets; avoid skull targets. Your score and the 30-second timer are verified on the server.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "A Target Rush round could not be started.", true);
      startButton.disabled = false;
    } finally {
      pending = false;
      targetButton.disabled = false;
    }
  };

  startButton.addEventListener("click", startRound);
  document.querySelectorAll("[data-rush-again]").forEach((button) => {
    button.addEventListener("click", startRound);
  });
  targetButton.addEventListener("click", async () => {
    if (previewMode) {
      hitPreviewTarget();
      return;
    }
    const token = targetButton.dataset.token;
    if (pending || !session || !token) return;
    const previousScore = session.score;
    const targetType = targetButton.dataset.kind;
    pending = true;
    targetButton.disabled = true;
    targetButton.hidden = true;
    try {
      const result = await api(`/api/games/target-rush/sessions/${session.id}/hit`, "POST", { token });
      attemptsRemaining = result.attempts_remaining_today;
      attempts.textContent = String(attemptsRemaining);
      rewardAmount = result.reward?.amount ?? rewardAmount;
      renderSession(result.session);
      if (["started", "in_progress"].includes(result.session.status)) {
        const delta = result.session.score - previousScore;
        if (targetType === "danger") {
          setStatus(delta < 0 ? `Danger hit! ${delta} points.` : "Danger hit! Your score is already at zero.");
        } else {
          setStatus(`Target hit! +${delta} points.`);
        }
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "That target could not be checked.", true);
      pending = false;
      await refreshSession();
    } finally {
      pending = false;
      targetButton.disabled = false;
    }
  });

  const load = async () => {
    if (previewMode) {
      goal.textContent = "200";
      attempts.textContent = "Demo only";
      rewardNode.parentElement.textContent = "Reward: none (preview)";
      startButton.disabled = false;
      setStatus("Preview mode: play without signing in. Scores and rewards are not saved.");
      return;
    }
    try {
      const { game } = await api("/api/games/target-rush");
      rewardAmount = game.reward_amount_coins;
      rewardNode.textContent = new Intl.NumberFormat().format(rewardAmount);
      goal.textContent = new Intl.NumberFormat().format(game.score_to_win);
      try {
        const current = await api("/api/games/target-rush/sessions/current");
        attemptsRemaining = current.attempts_remaining_today;
        attempts.textContent = String(attemptsRemaining);
        renderSession(current.session);
        if (current.session && ["started", "in_progress"].includes(current.session.status)) {
          setStatus("Resume your active round. The server controls every target, hit, score, and the 30-second timer.");
        } else if (!current.session) {
          setStatus("Ready when you are. Start a 30-second round to use one of your free daily attempts.");
        }
      } catch (error) {
        if (error.code === "AUTH_REQUIRED") {
          attempts.textContent = "Sign in required";
          startButton.disabled = true;
          setStatus("Sign in to play Target Rush and earn the server-verified reward.", true);
          return;
        }
        throw error;
      }
    } catch (error) {
      previewFallbackAvailable = true;
      startButton.disabled = false;
      startButton.setAttribute("aria-label", "Open no-reward demo preview");
      const message = error instanceof Error ? error.message : "Target Rush could not be loaded.";
      setStatus(`${message} Press Play to open the no-reward demo preview.`, true);
    }
  };

  load();
})();

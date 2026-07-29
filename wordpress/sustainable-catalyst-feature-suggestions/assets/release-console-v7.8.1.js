(function () {
  'use strict';

  var initialized = [];

  function asArray(nodes) {
    return Array.prototype.slice.call(nodes || []);
  }

  function hasInitialized(root) {
    return root && root.getAttribute('data-console-ready') === 'true';
  }

  function initConsole(root) {
    if (!root || hasInitialized(root)) return null;

    var screensRegion = root.querySelector('[data-console-screens]');
    var screens = asArray(root.querySelectorAll('[data-console-screen]'));
    if (!screensRegion || screens.length < 2) return null;

    var previous = root.querySelector('[data-console-action="previous"]');
    var toggle = root.querySelector('[data-console-action="toggle"]');
    var next = root.querySelector('[data-console-action="next"]');
    var currentOutput = root.querySelector('[data-console-current]');
    var currentLabel = root.querySelector('[data-console-current-label]');
    var announcer = root.querySelector('[data-console-announcer]');
    var toggleLabel = toggle ? toggle.querySelector('[data-console-label]') : null;
    var toggleIcon = toggle ? toggle.querySelector('[data-console-icon]') : null;
    var pauseLabel = toggle ? (toggle.getAttribute('data-console-pause-label') || 'Pause') : 'Pause';
    var playLabel = toggle ? (toggle.getAttribute('data-console-play-label') || 'Play') : 'Play';
    var pauseAria = toggle ? (toggle.getAttribute('data-console-pause-aria') || 'Pause release screens') : 'Pause release screens';
    var playAria = toggle ? (toggle.getAttribute('data-console-play-aria') || 'Play release screens') : 'Play release screens';
    var parsedInterval = parseInt(root.getAttribute('data-interval') || '7000', 10);
    var interval = isFinite(parsedInterval) ? Math.max(3000, parsedInterval) : 7000;
    var reducedQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var index = 0;
    var timer = null;
    var userPaused = !!(reducedQuery && reducedQuery.matches);
    var interactionPaused = false;
    var destroyed = false;

    function screenTitle(screen, screenIndex) {
      return screen.getAttribute('data-console-title') || ('Screen ' + String(screenIndex + 1));
    }

    function announce(message) {
      if (!announcer || !message) return;
      announcer.textContent = '';
      window.setTimeout(function () {
        if (!destroyed) announcer.textContent = message;
      }, 20);
    }

    function show(nextIndex, shouldAnnounce) {
      index = (nextIndex + screens.length) % screens.length;
      screens.forEach(function (screen, screenIndex) {
        var active = screenIndex === index;
        screen.setAttribute('data-console-active', active ? 'true' : 'false');
        screen.setAttribute('aria-hidden', active ? 'false' : 'true');
      });
      var title = screenTitle(screens[index], index);
      if (currentOutput) currentOutput.textContent = String(index + 1);
      if (currentLabel) currentLabel.textContent = title;
      if (shouldAnnounce) {
        announce(title + ', screen ' + String(index + 1) + ' of ' + String(screens.length));
      }
    }

    function updateToggle(shouldAnnounce) {
      if (!toggle) return;
      toggle.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
      toggle.setAttribute('aria-label', userPaused ? playAria : pauseAria);
      if (toggleLabel) toggleLabel.textContent = userPaused ? playLabel : pauseLabel;
      if (toggleIcon) toggleIcon.textContent = userPaused ? '▶' : '❚❚';
      if (shouldAnnounce) announce(userPaused ? 'Release screen rotation paused' : 'Release screen rotation playing');
    }

    function stop() {
      if (timer) window.clearInterval(timer);
      timer = null;
    }

    function start() {
      stop();
      if (destroyed || userPaused || interactionPaused || document.hidden) return;
      timer = window.setInterval(function () {
        show(index + 1, false);
      }, interval);
    }

    function temporaryPause(value) {
      interactionPaused = value;
      if (value) stop();
      else start();
    }

    function navigate(nextIndex) {
      show(nextIndex, true);
      start();
    }

    function onKeydown(event) {
      var target = event.target;
      var tagName = target && target.tagName ? target.tagName.toLowerCase() : '';
      if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') return;
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        navigate(index - 1);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        navigate(index + 1);
      } else if (event.key === 'Home') {
        event.preventDefault();
        navigate(0);
      } else if (event.key === 'End') {
        event.preventDefault();
        navigate(screens.length - 1);
      } else if ((event.key === ' ' || event.key === 'Spacebar') && target === screensRegion) {
        event.preventDefault();
        userPaused = !userPaused;
        updateToggle(true);
        start();
      }
    }

    function onReducedMotionChange(event) {
      if (event.matches) {
        userPaused = true;
        updateToggle(true);
        start();
      }
    }

    if (previous) previous.addEventListener('click', function () { navigate(index - 1); });
    if (next) next.addEventListener('click', function () { navigate(index + 1); });
    if (toggle) toggle.addEventListener('click', function () {
      userPaused = !userPaused;
      updateToggle(true);
      start();
    });

    root.addEventListener('mouseenter', function () { temporaryPause(true); });
    root.addEventListener('mouseleave', function () { temporaryPause(false); });
    root.addEventListener('focusin', function () { temporaryPause(true); });
    root.addEventListener('focusout', function (event) {
      if (!root.contains(event.relatedTarget)) temporaryPause(false);
    });
    root.addEventListener('keydown', onKeydown);

    if (reducedQuery) {
      if (typeof reducedQuery.addEventListener === 'function') reducedQuery.addEventListener('change', onReducedMotionChange);
      else if (typeof reducedQuery.addListener === 'function') reducedQuery.addListener(onReducedMotionChange);
    }

    screensRegion.setAttribute('tabindex', '0');
    root.setAttribute('data-console-ready', 'true');
    root.classList.add('scfs-release-board--enhanced');
    show(0, false);
    updateToggle(false);
    start();

    var controller = {
      root: root,
      start: start,
      destroy: function () {
        destroyed = true;
        stop();
      }
    };
    initialized.push(controller);
    return controller;
  }

  function bootWithin(scope) {
    var rootScope = scope && scope.querySelectorAll ? scope : document;
    if (rootScope.matches && rootScope.matches('[data-release-console="true"]')) initConsole(rootScope);
    asArray(rootScope.querySelectorAll('[data-release-console="true"]')).forEach(initConsole);
  }

  function onVisibilityChange() {
    initialized = initialized.filter(function (controller) {
      if (!controller.root || !document.documentElement.contains(controller.root)) {
        controller.destroy();
        return false;
      }
      controller.start();
      return true;
    });
  }

  function observeDynamicConsoles() {
    if (!window.MutationObserver || !document.body) return;
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        asArray(mutation.addedNodes).forEach(function (node) {
          if (node && node.nodeType === 1) bootWithin(node);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  function boot() {
    bootWithin(document);
    observeDynamicConsoles();
    document.addEventListener('visibilitychange', onVisibilityChange);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}());

/**
 * @file
 * Switches on the heading slide-in before the page is first painted.
 *
 * Loaded in the page head so headings are hidden from the start, rather than
 * shown and then hidden. js/heading-motion.js reveals them.
 */
(function (html) {

  'use strict';

  if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }
  html.classList.add('contract-heading-motion');

  // If heading-motion.js never runs (a script error elsewhere), show the
  // headings rather than leave them hidden.
  window.addEventListener('load', function () {
    window.setTimeout(function () {
      if (!html.classList.contains('contract-heading-motion--ready')) {
        html.classList.remove('contract-heading-motion');
      }
    }, 1000);
  });

} (document.documentElement));

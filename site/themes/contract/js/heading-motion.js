/**
 * @file
 * Reveals headings with a highlighted start as they scroll into view.
 *
 * See css/component/heading-motion.css for the animation.
 */
(function (Drupal, once) {

  'use strict';

  const html = document.documentElement;
  // Milliseconds between the starts of headings revealed together.
  const STAGGER = 150;
  let observer;
  // When the next heading may start, so headings revealed together (on page
  // load, or by a fast scroll) go one after another rather than all at once.
  let nextStart = 0;

  Drupal.behaviors.contractHeadingMotion = {
    attach (context) {
      if (!html.classList.contains('contract-heading-motion')) {
        return;
      }
      html.classList.add('contract-heading-motion--ready');

      observer = observer || new IntersectionObserver(function (entries) {
        entries
          .filter(function (entry) {
            return entry.isIntersecting;
          })
          // Top to bottom, then left to right.
          .sort(function (a, b) {
            return (a.boundingClientRect.top - b.boundingClientRect.top) || (a.boundingClientRect.left - b.boundingClientRect.left);
          })
          .forEach(function (entry) {
            const now = performance.now();
            const start = Math.max(now, nextStart);
            nextStart = start + STAGGER;
            entry.target.style.setProperty('--contract-heading-motion-delay', Math.round(start - now) + 'ms');
            entry.target.classList.add('is-revealed');
            observer.unobserve(entry.target);
          });
      });

      once('contract-heading-motion', '.c-heading__lead', context).forEach(function (lead) {
        const heading = lead.closest('h1, h2, h3, h4, h5, h6, .fieldset-legend');
        if (heading) {
          observer.observe(heading);
        }
      });
    }
  };

} (Drupal, once));

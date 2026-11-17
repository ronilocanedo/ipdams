/* IPDAMS v0.2.377 - graceful missing-branding display.
 * This script NEVER changes a branding image URL or replaces an existing logo.
 * It acts ONLY after the original image has failed to load. */
(function () {
  'use strict';
  var failed = new WeakMap();
  function brandingKind(image) {
    if (!image || image.tagName !== 'IMG' || !image.getAttribute) return null;
    var src = image.getAttribute('src') || '';
    var match = /^(?:\.\/|\/)?api\/branding\.php\?kind=(icon|header)(?:&[^#]*)?(?:#.*)?$/.exec(src);
    return match ? match[1] : null;
  }
  function restore(image) {
    var state = failed.get(image);
    if (!state) return;
    image.style.display = state.display;
    if (state.ariaHidden === null) image.removeAttribute('aria-hidden');
    else image.setAttribute('aria-hidden', state.ariaHidden);
    image.removeAttribute('data-ipdams-branding-error');
    if (state.label && state.label.parentNode) state.label.parentNode.removeChild(state.label);
    failed.delete(image);
  }
  function handleMissing(image) {
    var kind = brandingKind(image);
    if (!kind) return;
    var src = image.getAttribute('src') || '';
    var previous = failed.get(image);
    if (previous && previous.src === src) return;
    if (previous) restore(image);
    var state = {
      src: src,
      display: image.style.display,
      ariaHidden: image.getAttribute('aria-hidden'),
      label: null
    };
    image.setAttribute('data-ipdams-branding-error', 'true');
    image.setAttribute('aria-hidden', 'true');
    // Never show a broken-image symbol or long alt text when the asset is absent.
    image.style.display = 'none';
    // Text-only fallback for an unavailable header; no new logo or image.
    // Existing and owner-uploaded images retain their exact original appearance.
    if (kind === 'header' && image.parentNode) {
      var label = document.createElement('span');
      label.setAttribute('data-ipdams-branding-text-fallback', 'true');
      label.setAttribute('aria-label', 'IPDAMS branding image unavailable');
      label.textContent = 'IPDAMS';
      label.style.fontFamily = 'inherit';
      label.style.fontWeight = '700';
      label.style.letterSpacing = '0.025em';
      label.style.fontSize = '1.15rem';
      label.style.color = 'inherit';
      image.parentNode.insertBefore(label, image.nextSibling);
      state.label = label;
    }
    failed.set(image, state);
  }
  document.addEventListener('error', function (event) {
    handleMissing(event.target);
  }, true);
  // Restore a previously failed image if its source is fixed or updated later.
  document.addEventListener('load', function (event) {
    var image = event.target;
    if (image && image.tagName === 'IMG' && image.naturalWidth > 0) restore(image);
  }, true);
  // Also catch a rare failed image that completed before this handler loaded.
  window.addEventListener('load', function () {
    var images = document.querySelectorAll('img[src*="api/branding.php?kind="]');
    for (var i = 0; i < images.length; i++) {
      if (images[i].complete && images[i].naturalWidth === 0) handleMissing(images[i]);
    }
  });
}());

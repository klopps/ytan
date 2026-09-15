/**
 * Shared photo-upload widget for POI/Route/Area edit windows - generalizes
 * the same compress/stage/grid pattern tour-admin.js built for Tours
 * (compressTourPhoto()/stageTourFormPhoto()/tourFormPhotosInnerHtml()),
 * parametrized by entity type (the REST path's plural segment: 'pois',
 * 'routes' or 'areas') instead of hard-wired to tours. tour-admin.js itself
 * is left untouched - it already works and was recently hardened (spinner
 * tiles, stale-async guards), so it isn't worth the regression risk to
 * fold it into this shared module too. Reuses the same size/compression
 * tuning constants as tours (TOUR_PHOTO_MAX_BYTES etc., config.js) and the
 * same `.tour-photo-*` CSS classes (style.css) - only the per-entity max
 * *count* differs (POI_PHOTO_MAX_COUNT etc.), and only the class names look
 * tour-specific, which is cosmetic (devtools only) and not worth a risky
 * rename sweep across already-tested tour code.
 *
 * POI/Route/Area edit windows (unlike the Tours panel) open synchronously
 * from an already-cached client-side list - no fresh network fetch on
 * open, so the window itself never has to wait on a network roundtrip.
 * This module preserves that: initPhotoUpload() returns immediately, and
 * the entity's existing photos are fetched in the background via a
 * dedicated GET .../images list endpoint (which Tour doesn't need, since
 * tour-admin.js already re-fetches the whole tour incl. images on every
 * edit-form open) - the grid briefly shows empty, then repaints once that
 * background fetch resolves. Only one POI/Route/Area edit window is ever
 * open at a time, so this module needs only one set of module-level state
 * variables, not one per open window.
 */

const PHOTO_UPLOAD_CONTAINER_ID = 'photoUploadWrap';

let photoUploadEntityType = null; // 'pois' | 'routes' | 'areas' - the REST path's plural segment
let photoUploadEntityId = null;
let photoUploadMaxCount = 0;
let photoUploadExistingImages = [];
let photoUploadPendingUploads = []; // File objects added but not yet uploaded - applied only when Save is clicked
let photoUploadPendingRemovals = []; // ids of existing images marked for removal but not yet deleted - applied only when Save is clicked
let photoUploadProcessingCount = 0; // oversized photos currently running through compressPhotoUpload()
let photoUploadRenderToken = 0; // bumped on every initPhotoUpload() call; a stale background fetch/compression checks against it and no-ops

/**
 * Resets the widget's state for editing one entity's photos and kicks off
 * a background fetch of its existing images. Call once per edit-window
 * open, right before embedding photoUploadGridHtml() into that window's
 * synchronously-built content string.
 */
function initPhotoUpload(entityType, entityId, maxCount) {
    var myToken = ++photoUploadRenderToken;
    photoUploadEntityType = entityType;
    photoUploadEntityId = entityId;
    photoUploadMaxCount = maxCount;
    photoUploadExistingImages = [];
    photoUploadPendingUploads = [];
    photoUploadPendingRemovals = [];
    photoUploadProcessingCount = 0;

    Ytan.get('/' + entityType + '/' + entityId + '/images').then(function (answer) {
        if (myToken !== photoUploadRenderToken) {
            return;
        }
        photoUploadExistingImages = answer.data || [];
        refreshPhotoUploadGrid();
    }).catch(function (err) {
        log('initPhotoUpload() failed to load existing images', LOG_ERROR, err);
    });
}

function photoUploadTotalCount() {
    var visibleImages = photoUploadExistingImages.filter(img => !photoUploadPendingRemovals.includes(img.id));
    return visibleImages.length + photoUploadPendingUploads.length + photoUploadProcessingCount;
}

function photoUploadGridHtml() {
    var visibleImages = photoUploadExistingImages.filter(img => !photoUploadPendingRemovals.includes(img.id));
    var totalCount = photoUploadTotalCount();

    var html = '<div class="tour-photo-grid" id="photoUploadGrid">';
    for (let i = 0; i < visibleImages.length; i++) {
        var img = visibleImages[i];
        html += '<div class="tour-photo-tile">' +
            '<img data-photo-entity-type="' + photoUploadEntityType + '" data-photo-entity-id="' + photoUploadEntityId + '" data-image-id="' + img.id + '">' +
            '<div class="tour-photo-remove" onclick="removeExistingPhotoUpload(' + img.id + ');"><i class="material-icons-round">close</i></div>' +
            '</div>';
    }
    for (let i = 0; i < photoUploadPendingUploads.length; i++) {
        // Not yet uploaded - preview straight from the local File via a
        // blob: URL rather than the fetchBlob() path below, which is only
        // for photos that already exist on the server.
        html += '<div class="tour-photo-tile tour-photo-tile-pending" title="' + t('photo_upload.pending_title') + '">' +
            '<img src="' + URL.createObjectURL(photoUploadPendingUploads[i]) + '">' +
            '<div class="tour-photo-remove" onclick="removePendingPhotoUpload(' + i + ');"><i class="material-icons-round">close</i></div>' +
            '</div>';
    }
    for (let i = 0; i < photoUploadProcessingCount; i++) {
        html += '<div class="tour-photo-tile tour-photo-processing" title="' + t('photo_upload.compressing') + '">' +
            '<i class="material-icons-round tour-photo-spinner">autorenew</i>' +
            '<span class="tour-photo-processing-label">' + t('photo_upload.compressing') + '</span>' +
            '</div>';
    }
    if (totalCount < photoUploadMaxCount) {
        html += '<div class="tour-photo-tile tour-photo-add" onclick="document.getElementById(\'photoUploadInput\').click();">' +
            '<i class="material-icons-round">add</i></div>';
    }
    html += '</div>' +
        '<input type="file" id="photoUploadInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="stagePhotoUpload(event);">' +
        '<p class="hint tour-photo-hint">' + t('photo_upload.hint') + '</p>';

    return html;
}

/**
 * Re-renders the grid in place. A no-op if the owning edit window has
 * since closed (its container removed from the DOM) - POI/Route/Area edit
 * windows close synchronously on Save/Cancel, so a still-running
 * compression or background image fetch can resolve after there's nothing
 * left to paint into.
 */
function refreshPhotoUploadGrid() {
    var container = document.getElementById(PHOTO_UPLOAD_CONTAINER_ID);
    if (!container) {
        return;
    }
    container.innerHTML = photoUploadGridHtml();
    loadPhotoUploadImagesInto(container);
}

function loadPhotoUploadImagesInto(container) {
    var imgs = container.querySelectorAll('img[data-photo-entity-type][data-photo-entity-id][data-image-id]');
    for (let i = 0; i < imgs.length; i++) {
        renderPhotoUploadImage(imgs[i].dataset.photoEntityType, imgs[i].dataset.photoEntityId, imgs[i].dataset.imageId, imgs[i]);
    }
}

/**
 * Fetches a (possibly private) photo through the authenticated Blob
 * endpoint and points the given <img> at it - a plain <img src="..."> can't
 * attach the Bearer token a private entity's photo requires, see
 * api-client.js's fetchBlob(). Mirrors tour-admin.js's renderTourImage().
 */
function renderPhotoUploadImage(entityType, entityId, imageId, imgEl) {
    Ytan.fetchBlob('/' + entityType + '/' + entityId + '/images/' + imageId).then(function (blob) {
        imgEl.src = URL.createObjectURL(blob);
    }).catch(function (err) {
        log('renderPhotoUploadImage() failed', LOG_ERROR, err);
    });
}

/**
 * Stages one or more photos locally (preview only, via blob: URLs) instead
 * of uploading them immediately - the actual POST happens in
 * applyPendingPhotoUploadChanges(), only once the entity's Save button is
 * clicked. Directly mirrors tour-admin.js's stageTourFormPhoto() - see its
 * doc comment for the full rationale (immediate-vs-compressed split,
 * concurrent compression with one spinner tile each, stale-async guard).
 */
function stagePhotoUpload(event) {
    var files = Array.prototype.slice.call(event.target.files);
    event.target.value = ''; // clear either way, so picking the same file(s) again still fires onchange
    if (files.length === 0) {
        return;
    }

    var remainingSlots = photoUploadMaxCount - photoUploadTotalCount();
    if (files.length > remainingSlots) {
        showToast(t('photo_upload.limit_reached', { max: photoUploadMaxCount }), 'warning');
        files = files.slice(0, Math.max(0, remainingSlots));
    }
    if (files.length === 0) {
        return;
    }

    var immediate = files.filter(file => file.size <= TOUR_PHOTO_MAX_BYTES);
    var oversized = files.filter(file => file.size > TOUR_PHOTO_MAX_BYTES);

    immediate.forEach(file => photoUploadPendingUploads.push(file));

    if (oversized.length === 0) {
        refreshPhotoUploadGrid();
        return;
    }

    var myToken = photoUploadRenderToken;
    photoUploadProcessingCount += oversized.length;
    refreshPhotoUploadGrid();

    Promise.all(oversized.map(function (file) {
        return compressPhotoUpload(file).catch(function (err) {
            log('compressPhotoUpload() failed', LOG_ERROR, err);
            return null;
        });
    })).then(function (results) {
        if (myToken !== photoUploadRenderToken) {
            return;
        }

        var failureCount = 0;
        results.forEach(function (result) {
            if (result === null || result.size > TOUR_PHOTO_MAX_BYTES) {
                failureCount++;
                return;
            }
            photoUploadPendingUploads.push(result);
        });
        photoUploadProcessingCount -= oversized.length;
        refreshPhotoUploadGrid();
        if (failureCount > 0) {
            showToast(t('photo_upload.too_large'), 'error');
        }
    });
}

/**
 * Same quality/downscale ladder as tour-admin.js's compressTourPhoto() -
 * reuses the identical TOUR_PHOTO_* tuning constants (config.js) so
 * behaviour matches tours exactly, per the feature request.
 *
 * @param {File} file
 * @returns {Promise<File>}
 */
function compressPhotoUpload(file) {
    return createImageBitmap(file).then(function (bitmap) {
        return compressPhotoUploadBitmapRound(bitmap, TOUR_PHOTO_MAX_DIMENSION_PX, 0);
    }).then(function (blob) {
        var baseName = file.name ? file.name.replace(/\.[^.]+$/, '') : 'photo';
        return new File([blob], baseName + '.jpg', { type: 'image/jpeg' });
    });
}

function compressPhotoUploadBitmapRound(bitmap, maxDimension, round) {
    var scale = Math.min(1, maxDimension / Math.max(bitmap.width, bitmap.height));
    var canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    return tryPhotoUploadQualitySteps(canvas, 0).then(function (blob) {
        if (blob.size <= TOUR_PHOTO_MAX_BYTES || round >= TOUR_PHOTO_MAX_DOWNSCALE_ROUNDS) {
            bitmap.close();
            return blob;
        }
        return compressPhotoUploadBitmapRound(bitmap, Math.round(maxDimension / 2), round + 1);
    });
}

function tryPhotoUploadQualitySteps(canvas, stepIndex) {
    var quality = TOUR_PHOTO_QUALITY_STEPS[Math.min(stepIndex, TOUR_PHOTO_QUALITY_STEPS.length - 1)];
    return new Promise(function (resolve) {
        canvas.toBlob(resolve, 'image/jpeg', quality);
    }).then(function (blob) {
        if (blob.size <= TOUR_PHOTO_MAX_BYTES || stepIndex >= TOUR_PHOTO_QUALITY_STEPS.length - 1) {
            return blob;
        }
        return tryPhotoUploadQualitySteps(canvas, stepIndex + 1);
    });
}

function removePendingPhotoUpload(index) {
    photoUploadPendingUploads.splice(index, 1);
    refreshPhotoUploadGrid();
}

/**
 * Marks an already-saved photo for removal - the actual DELETE happens in
 * applyPendingPhotoUploadChanges() on Save, so a Cancel leaves it untouched.
 */
function removeExistingPhotoUpload(imageId) {
    photoUploadPendingRemovals.push(imageId);
    refreshPhotoUploadGrid();
}

/**
 * Applies staged uploads/removals to the server - call from the entity's
 * own save function, chained after its main PUT succeeds, passing the same
 * entityType/entityId initPhotoUpload() was called with. Works purely off
 * these module-level arrays (no DOM dependency), so it still runs correctly
 * even if the edit window has already closed by the time this resolves.
 */
function applyPendingPhotoUploadChanges(entityType, entityId) {
    var removals = photoUploadPendingRemovals.map(imageId => Ytan.del('/' + entityType + '/' + entityId + '/images/' + imageId));
    var uploads = photoUploadPendingUploads.map(file => {
        var formData = new FormData();
        formData.append('image', file);
        return Ytan.postFile('/' + entityType + '/' + entityId + '/images', formData);
    });

    if (removals.length === 0 && uploads.length === 0) {
        return Promise.resolve();
    }
    return Promise.all(removals.concat(uploads)).catch(err => {
        showToast(t('photo_upload.changes_failed', { error: err.message }), 'error');
    });
}

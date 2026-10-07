/**
 * Belpost tracking proxy — web app entry (doPost).
 * Map + direct search run from Google's network; no SpreadsheetApp.
 */

var DIRECT_SEARCH_MAX_ATTEMPTS = 5;
var DIRECT_SEARCH_DELAY_MS = 2000;
var MAP_MAX_PAGES = 40;
var MAP_FETCH_MAX_ATTEMPTS = 3;

/**
 * @param {GoogleAppsScript.Events.DoPost} e
 * @return {GoogleAppsScript.Content.TextOutput}
 */
function doPost(e) {
  var body;
  try {
    body = JSON.parse(e.postData.contents);
  } catch (parseError) {
    return jsonOutput(400, { error: 'Invalid JSON body' });
  }

  var expectedSecret = PropertiesService.getScriptProperties().getProperty('TRACKING_PROXY_SECRET');
  var providedSecret = body.secret;

  if (!expectedSecret || !providedSecret || providedSecret !== expectedSecret) {
    return jsonOutput(403, { error: 'Forbidden' });
  }

  var authToken = body.authToken;
  if (!authToken) {
    return jsonOutput(400, { error: 'authToken is required' });
  }

  var items = body.items;
  var mapOnlyMode = !Object.prototype.hasOwnProperty.call(body, 'items') ||
    !Array.isArray(items);

  if (mapOnlyMode) {
    var mapLoad = loadBelpostMap(authToken);
    if (!mapLoad.anyPageLoaded) {
      return jsonOutput(502, { error: 'Belpost map could not be loaded' });
    }

    var mapPayload = [];
    var mapData = mapLoad.map;
    for (var trackKey in mapData) {
      if (!mapData.hasOwnProperty(trackKey)) {
        continue;
      }
      var entry = mapData[trackKey];
      mapPayload.push({
        track: trackKey,
        event: entry ? entry.event : null,
        createdAt: entry ? entry.createdAt : null
      });
    }

    return jsonOutput(200, { map: mapPayload });
  }

  var results = [];

  for (var i = 0; i < items.length; i++) {
    var row = items[i];
    var track = row && row.track ? String(row.track).trim() : '';

    if (!track) {
      results.push({
        track: track,
        found: false,
        event: null,
        createdAt: null,
        targetStatus: null
      });
      continue;
    }

    var directResult = directBelpostSearch(track, authToken);

    if (directResult) {
      results.push({
        track: track,
        found: true,
        event: directResult.event,
        createdAt: directResult.createdAt,
        targetStatus: directResult.targetStatus
      });
    } else {
      results.push({
        track: track,
        found: false,
        event: null,
        createdAt: null,
        targetStatus: null
      });
    }
  }

  return jsonOutput(200, { results: results });
}

/**
 * @param {number} statusCode
 * @param {Object} payload
 * @return {GoogleAppsScript.Content.TextOutput}
 */
function jsonOutput(statusCode, payload) {
  // statusCode is part of the contract for operators/tests; Apps Script web apps may still return HTTP 200.
  return ContentService.createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}

/**
 * HTTP request with retries (from FunctionalCRM/System.gs).
 * @param {string} url
 * @param {Object} options
 * @param {Object} [config]
 * @return {GoogleAppsScript.URL_Fetch.HTTPResponse|null}
 */
function fetchWithRetry(url, options, config) {
  config = config || {};
  var maxAttempts = config.maxAttempts || 3;
  var delayMs = typeof config.delayMs === 'number' ? config.delayMs : 0;
  var isSuccess = config.isSuccess || function (response) {
    return response.getResponseCode() === 200;
  };

  for (var attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      var response = UrlFetchApp.fetch(url, options);
      if (isSuccess(response)) {
        return response;
      }
      Logger.log(
        'fetchWithRetry: attempt ' + attempt + '/' + maxAttempts +
        ' failed, HTTP ' + response.getResponseCode()
      );
    } catch (err) {
      Logger.log(
        'fetchWithRetry: attempt ' + attempt + '/' + maxAttempts +
        ' exception: ' + err.message
      );
    }
    if (attempt < maxAttempts && delayMs > 0) {
      Utilities.sleep(delayMs);
    }
  }

  return null;
}

/**
 * Load Belpost tracking pages into a map (from FunctionalCRM/General.gs).
 * @param {string} authToken
 * @return {{map: Object, anyPageLoaded: boolean}}
 */
function loadBelpostMap(authToken) {
  Logger.log('===== Belpost map load start =====');

  var map = {};
  var anyPageLoaded = false;
  var currentPage = 1;
  var maxPages = MAP_MAX_PAGES;
  var totalPages = 1;

  while (currentPage <= Math.min(maxPages, totalPages)) {
    Logger.log('Loading map page ' + currentPage + '...');

    var url = 'https://api.belpost.by/api/v1/tracking?page=' + currentPage;
    var options = {
      method: 'get',
      headers: {
        'Cache-Control': 'no-cache',
        'Content-Type': 'application/json',
        Authorization: authToken
      },
      muteHttpExceptions: true
    };

    var response = fetchWithRetry(url, options, {
      maxAttempts: MAP_FETCH_MAX_ATTEMPTS,
      delayMs: 0,
      isSuccess: function (resp) {
        return resp.getResponseCode() === 200;
      }
    });

    if (!response) {
      Logger.log('Map page ' + currentPage + ' failed after retries; skipping.');
      currentPage++;
      continue;
    }

    anyPageLoaded = true;

    var obj;
    try {
      obj = JSON.parse(response.getContentText());
    } catch (parseError) {
      Logger.log('JSON parse error on map page ' + currentPage + ': ' + parseError.message);
      currentPage++;
      continue;
    }

    if (!obj.data || !Array.isArray(obj.data)) {
      Logger.log('Invalid map response structure on page ' + currentPage);
      currentPage++;
      continue;
    }

    if (obj.last_page) {
      totalPages = obj.last_page;
    }

    for (var i = 0; i < obj.data.length; i++) {
      var item = obj.data[i];
      if (!item.number) {
        continue;
      }
      var trackNum = String(item.number).trim();
      if (item.last_event) {
        map[trackNum] = {
          event: item.last_event.event,
          createdAt: item.last_event.created_at
        };
      } else {
        map[trackNum] = {
          event: null,
          createdAt: null
        };
      }
    }

    currentPage++;
  }

  Logger.log(
    '===== Map load done: ' + Object.keys(map).length + ' tracks, anyPageLoaded=' + anyPageLoaded + ' ====='
  );

  return { map: map, anyPageLoaded: anyPageLoaded };
}

function isBelpostReturnMarker(step) {
  if (!step) {
    return false;
  }
  return step.code === 25 ||
    step.event === 'Подготовлено для возврата' ||
    step.event === 'Вручено отправителю';
}

function hasBelpostReturnHistory(steps) {
  if (!steps || !steps.length) {
    return false;
  }
  for (var i = 0; i < steps.length; i++) {
    if (isBelpostReturnMarker(steps[i])) {
      return true;
    }
  }
  return false;
}

function parseBelpostTrackingItem(item) {
  if (!item || !item.steps || !Array.isArray(item.steps) || item.steps.length === 0) {
    return { event: null, createdAt: null, targetStatus: null };
  }

  var steps = item.steps;
  var latest = steps[0];
  var event = latest.event;
  var createdAt = latest.created_at;

  if (event === 'Вручено отправителю') {
    return { event: event, createdAt: createdAt, targetStatus: 'Возврат' };
  }
  if (hasBelpostReturnHistory(steps)) {
    return { event: event, createdAt: createdAt, targetStatus: 'Возврат в пути' };
  }
  return { event: event, createdAt: createdAt, targetStatus: null };
}

function parseBelpostTrackingFromMapEntry(mapEntry) {
  if (!mapEntry || !mapEntry.event) {
    return { event: null, createdAt: null, targetStatus: null };
  }

  var event = mapEntry.event;
  var createdAt = mapEntry.createdAt;

  if (event === 'Вручено отправителю') {
    return { event: event, createdAt: createdAt, targetStatus: 'Возврат' };
  }
  if (event === 'Подготовлено для возврата') {
    return { event: event, createdAt: createdAt, targetStatus: 'Возврат в пути' };
  }
  return { event: event, createdAt: createdAt, targetStatus: null };
}

function isBelpostTransitEvent(event) {
  return event === 'Отправлено' || event === 'Поступило в обработку';
}

function needsBelpostStepsLookup(mapEntry, currentStatus) {
  if (!mapEntry || !mapEntry.event) {
    return false;
  }
  if (mapEntry.event === 'Поступило в учреждение доставки') {
    return true;
  }
  if (currentStatus === 'В отделении' && isBelpostTransitEvent(mapEntry.event)) {
    return true;
  }
  return false;
}

function resolveBelpostTracking(trackNumber, mapEntry, currentStatus) {
  if (mapEntry && !needsBelpostStepsLookup(mapEntry, currentStatus)) {
    return parseBelpostTrackingFromMapEntry(mapEntry);
  }

  var directResult = directBelpostSearch(trackNumber, null);
  if (directResult) {
    return directResult;
  }
  if (mapEntry) {
    return parseBelpostTrackingFromMapEntry(mapEntry);
  }
  return null;
}

function directBelpostSearch(trackNumber, authToken) {
  Logger.log('Direct search track: ' + trackNumber);

  var headers = {
    'Content-Type': 'application/json'
  };
  if (authToken) {
    headers.Authorization = authToken;
  }

  var url = 'https://api.belpost.by/api/v1/tracking';
  var options = {
    method: 'post',
    headers: headers,
    payload: JSON.stringify({ number: trackNumber }),
    muteHttpExceptions: true
  };

  var response = fetchWithRetry(url, options, {
    maxAttempts: DIRECT_SEARCH_MAX_ATTEMPTS,
    delayMs: DIRECT_SEARCH_DELAY_MS,
    isSuccess: function (resp) {
      return resp.getResponseCode() === 200;
    }
  });

  if (!response) {
    Logger.log('Direct search failed after retries: ' + trackNumber);
    return null;
  }

  var obj;
  try {
    obj = JSON.parse(response.getContentText());
  } catch (parseError) {
    Logger.log('Direct search JSON parse error: ' + parseError.message);
    return null;
  }

  if (!obj.data || !Array.isArray(obj.data) || obj.data.length === 0) {
    Logger.log('Direct search empty data: ' + trackNumber);
    return null;
  }

  return parseBelpostTrackingItem(obj.data[0]);
}

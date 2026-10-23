/**
 * Pure helpers for METSIS GeoJSON feature hover/click interactions.
 */

export const HOVER_TITLE_LIMIT = 5;
export const GEOJSON_LAYER_FLAG = "metsisGeojsonLayer";
export const FEATURE_STATE_KEY = "metsisFeatureState";

/**
 * Return a translated string using Drupal.t when available.
 */
export function t(text, args) {
  const drupal = globalThis.Drupal;
  if (drupal && typeof drupal.t === "function") {
    return drupal.t(text, args);
  }
  if (!args) {
    return text;
  }
  return Object.keys(args).reduce(
    (result, key) => result.split(key).join(String(args[key])),
    text,
  );
}

/**
 * Remove duplicate items, keeping the first occurrence of each key.
 *
 * Wrapped worlds and multi-part hits can report the same dataset twice.
 */
export function uniqueBy(items, keyFn) {
  const seen = new Set();
  return items.filter((item) => {
    const key = keyFn(item);
    if (key === undefined || key === null || key === "" || seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

/**
 * Split titles into the visible part and the number of hidden titles.
 */
export function summarizeTitles(titles, limit = HOVER_TITLE_LIMIT) {
  const filtered = titles.filter((title) => title);
  return {
    visible: filtered.slice(0, limit),
    remaining: Math.max(0, filtered.length - limit),
  };
}

/**
 * Format an ISO date-time as its date part.
 */
export function formatDate(value) {
  if (!value) {
    return "";
  }
  /*const match = /^(\d{4}-\d{2}-\d{2})/.exec(String(value));
  return match ? match[1] : String(value);*/
  return value;
}

/**
 * Format a temporal extent in the short "start to end" notation.
 */
export function formatTemporalShort(start, end) {
  const startDate = formatDate(start);
  if (!startDate) {
    return "";
  }
  const endDate = formatDate(end);
  return endDate
    ? t("@start to @end", { "@start": startDate, "@end": endDate })
    : startDate;
}

/**
 * Return an HTTP(S) URL or an empty string for anything else.
 */
export function safeHttpUrl(value) {
  if (!value) {
    return "";
  }
  try {
    const url = new URL(String(value), globalThis.location?.href);
    return url.protocol === "http:" || url.protocol === "https:"
      ? url.href
      : "";
  } catch {
    return "";
  }
}

/**
 * Compute the longitude/latitude extent of GeoJSON geometry coordinates.
 */
export function lonLatExtent(geometry) {
  const extent = [Infinity, Infinity, -Infinity, -Infinity];
  const visit = (coords) => {
    if (!Array.isArray(coords)) {
      return;
    }
    if (typeof coords[0] === "number" && typeof coords[1] === "number") {
      extent[0] = Math.min(extent[0], coords[0]);
      extent[1] = Math.min(extent[1], coords[1]);
      extent[2] = Math.max(extent[2], coords[0]);
      extent[3] = Math.max(extent[3], coords[1]);
      return;
    }
    coords.forEach(visit);
  };
  if (geometry?.type === "GeometryCollection") {
    (geometry.geometries || []).forEach((part) =>
      visit(part?.coordinates ?? []),
    );
  } else {
    visit(geometry?.coordinates);
  }
  return extent.every(Number.isFinite) ? extent : null;
}

/**
 * Whether a GeoJSON geometry covers (almost) the whole globe.
 *
 * Evaluated on EPSG:4326 input, because projected extents are clamped or
 * distorted (e.g. polar stereographic) and cannot be compared to 360 degrees.
 */
export function isGlobeSpanning(geometry) {
  const extent = lonLatExtent(geometry);
  if (!extent) {
    return false;
  }
  const width = extent[2] - extent[0];
  const height = extent[3] - extent[1];
  return width >= 350 || width * height >= 0.5 * 360 * 180;
}

/**
 * Resolve the visual state of a feature from hover/selection state.
 *
 * @return {"active"|"muted"|"default"}
 */
export function featureVisualState(id, state) {
  if (!state) {
    return "default";
  }
  if (state.hovered?.has(id) || state.selected?.has(id)) {
    return "active";
  }
  return state.selected?.size ? "muted" : "default";
}

/**
 * Whether two sets contain the same values.
 */
export function sameSet(a, b) {
  if (a.size !== b.size) {
    return false;
  }
  for (const value of a) {
    if (!b.has(value)) {
      return false;
    }
  }
  return true;
}

/**
 * Return the METSIS GeoJSON result layers added to an OpenLayers map.
 */
export function getGeojsonLayers(map) {
  return map
    .getLayers()
    .getArray()
    .filter((layer) => layer.get(GEOJSON_LAYER_FLAG));
}

/**
 * Return the unique result features rendered at a map pixel.
 */
export function getResultFeaturesAtPixel(map, pixel) {
  const features = map.getFeaturesAtPixel(pixel, {
    layerFilter: (layer) => Boolean(layer.get(GEOJSON_LAYER_FLAG)),
    hitTolerance: 3,
  });
  return uniqueBy(features, (feature) => feature.get("id"));
}

/**
 * Update the hovered or selected ids and re-render the layers on change.
 *
 * @param {object} map
 *   The OpenLayers map.
 * @param {"hovered"|"selected"} key
 *   The state set to replace.
 * @param {Iterable<string>} ids
 *   Feature ids.
 */
export function setFeatureState(map, key, ids) {
  const next = new Set(ids);
  getGeojsonLayers(map).forEach((layer) => {
    const state = layer.get(FEATURE_STATE_KEY);
    if (state && !sameSet(state[key], next)) {
      state[key] = next;
      layer.changed();
    }
  });
}

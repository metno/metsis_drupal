import { useEffect } from "preact/hooks";
import VectorLayer from "ol/layer/Vector.js";
import VectorSource from "ol/source/Vector.js";
import GeoJSON from "ol/format/GeoJSON.js";
import Point from "ol/geom/Point.js";
import { getCenter } from "ol/extent.js";
import Style from "ol/style/Style.js";
import Fill from "ol/style/Fill.js";
import Stroke from "ol/style/Stroke.js";
import Icon from "ol/style/Icon.js";
import pinBlack from "@assets/pin-black.svg";
import pinBlue from "@assets/pin-blue.svg";
import pinGrey from "@assets/pin-grey.svg";
import {
  FEATURE_STATE_KEY,
  GEOJSON_LAYER_FLAG,
  featureVisualState,
  isGlobeSpanning,
} from "@utils/featureInfo.js";

const SMALL_GEOMETRY_PIXEL_THRESHOLD = 10;
const pinSources = { default: pinBlack, active: pinBlue, muted: pinGrey };

const pinStyles = {};
const centroidPinStyles = {};
Object.entries(pinSources).forEach(([state, src]) => {
  const image = new Icon({ src, scale: 0.1, anchor: [0.5, 1] });
  // Active pins are drawn above the others.
  const zIndex = state === "active" ? 2 : state === "default" ? 1 : 0;
  pinStyles[state] = new Style({ image, zIndex });
  centroidPinStyles[state] = new Style({
    image,
    zIndex,
    geometry: (feature) =>
      new Point(getCenter(feature.getGeometry().getExtent())),
  });
});

// Globe-spanning polygons are nearly transparent: many of them stack on every
// result page, and smaller polygons must stay visible underneath. A Fill is
// still required so their interior remains hit-detectable.
const polygonColors = {
  default: {
    regular: ["rgba(49, 12, 214, 0.5)", "rgba(23, 6, 96, 1)", 2],
    globe: ["rgba(52, 5, 79, 0.02)", "rgba(65, 5, 100, 0.6)", 1],
  },
  active: {
    regular: ["rgba(0, 120, 255, 0.55)", "rgba(0, 120, 255, 1)", 3],
    globe: ["rgba(0, 120, 255, 0.04)", "rgba(0, 120, 255, 1)", 2],
  },
  muted: {
    regular: ["rgba(120, 120, 120, 0.15)", "rgba(110, 110, 110, 0.8)", 1],
    globe: ["rgba(120, 120, 120, 0.01)", "rgba(110, 110, 110, 0.5)", 1],
  },
};
const polygonStyles = {};
Object.entries(polygonColors).forEach(([state, variants]) => {
  polygonStyles[state] = {};
  Object.entries(variants).forEach(([variant, [fill, stroke, width]]) => {
    polygonStyles[state][variant] = new Style({
      fill: new Fill({ color: fill }),
      stroke: new Stroke({ color: stroke, width }),
      // Globe polygons are drawn first; active ones above inactive ones.
      zIndex:
        (variant === "globe" ? -10 : 0) +
        (state === "active" ? 2 : state === "default" ? 1 : 0),
    });
  });
});

const GeoJSONLayer = ({ mapInstance, geojsonFeatures, projection }) => {
  useEffect(() => {
    if (!mapInstance || !projection || !geojsonFeatures) return;
    // Always read GeoJSON as EPSG:4326 and transform to map's projection
    const features = new GeoJSON().readFeatures(geojsonFeatures, {
      dataProjection: "EPSG:4326",
      featureProjection: projection,
    });
    // The world check needs the untransformed lon/lat geometry.
    const sourceFeatures = geojsonFeatures.features || [];
    features.forEach((feature, index) => {
      feature.set(
        "globe_spanning",
        isGlobeSpanning(sourceFeatures[index]?.geometry),
        true,
      );
    });

    const source = new VectorSource({ wrapX: true, features });
    const state = { hovered: new Set(), selected: new Set() };

    const layer = new VectorLayer({
      source: source,
      style: (feature, resolution) =>
        styleFunction(
          feature,
          resolution,
          featureVisualState(feature.get("id"), state),
        ),
    });
    layer.set(GEOJSON_LAYER_FLAG, true);
    layer.set(FEATURE_STATE_KEY, state);
    mapInstance.addLayer(layer);

    // Optionally fit map to features
    if (features.length) {
      mapInstance.getView().fit(source.getExtent(), { maxZoom: 16 });
      mapInstance.getView().setZoom(mapInstance.getView().getZoom() - 0.3);
    }

    // Cleanup: Remove the layer when the component unmounts or geojsonData/projection changes.
    return () => {
      mapInstance.removeLayer(layer);
    };
  }, [mapInstance, projection, geojsonFeatures]);

  return null;
};

function styleFunction(feature, resolution, visualState) {
  const geometry = feature.getGeometry();
  const geometryType = geometry.getType();
  if (geometryType === "Point" || geometryType === "MultiPoint") {
    return pinStyles[visualState];
  }

  if (Number.isFinite(resolution) && resolution > 0) {
    const extent = geometry.getExtent();
    if (extent.every(Number.isFinite)) {
      const widthInPixels = (extent[2] - extent[0]) / resolution;
      const heightInPixels = (extent[3] - extent[1]) / resolution;
      if (
        widthInPixels < SMALL_GEOMETRY_PIXEL_THRESHOLD ||
        heightInPixels < SMALL_GEOMETRY_PIXEL_THRESHOLD
      ) {
        return centroidPinStyles[visualState];
      }
    }
  }

  return polygonStyles[visualState][
    feature.get("globe_spanning") ? "globe" : "regular"
  ];
}

export default GeoJSONLayer;

import { useEffect, useMemo, useRef, useState } from "preact/hooks";
import TileLayer from "ol/layer/WebGLTile.js";
import TileWMS from "ol/source/TileWMS.js";
import WMSCapabilities from "ol/format/WMSCapabilities.js";
import {
  chooseBestProjectionForExtent,
  deriveCapabilitiesVersion,
  fitViewToGeographicExtent,
  mergeGeographicExtents,
  switchMapViewProjection,
} from "@utils/mapProjection";
import {
  buildBlacklistedLayerSet,
  collectNamedLayers,
  selectInitialLayer,
} from "@utils/wmsLayerUtils";
import { buildInitialDimParams } from "@utils/wmsDimensions";
import WMSDimensionControls from "@components/WMSDimensionControls.jsx";
import WmsSpinner from "@components/WmsSpinner.jsx";

function normalizeEndpoints(wmsConfig) {
  const configuredEndpoints = Array.isArray(wmsConfig?.endpoints)
    ? wmsConfig.endpoints
    : [];

  if (configuredEndpoints.length > 0) {
    return configuredEndpoints
      .filter((endpoint) => endpoint?.serviceUrl)
      .map((endpoint, index) => ({
        id: endpoint.id || `wms_${index}`,
        label: endpoint.label || endpoint.serviceUrl,
        serviceUrl: endpoint.serviceUrl,
        capabilitiesUrl: endpoint.capabilitiesUrl || endpoint.serviceUrl,
      }));
  }

  const rawUrls = wmsConfig?.wmsUrls;
  const urls = Array.isArray(rawUrls) ? rawUrls : rawUrls ? [rawUrls] : [];

  return urls.filter(Boolean).map((url, index) => ({
    id: `wms_${index}`,
    label: url,
    serviceUrl: url,
    capabilitiesUrl: url,
  }));
}

const WMSLayerManager = ({
  mapInstance,
  wmsConfig,
  currentProjection,
  supportedProjectionCodes = [],
  onProjectionChange,
  onWmsLayerChange,
}) => {
  const [activeEndpointId, setActiveEndpointId] = useState("");
  const [availableLayers, setAvailableLayers] = useState([]);
  const [selectedLayer, setSelectedLayer] = useState("");
  const [selectedStyle, setSelectedStyle] = useState("");
  const [wmsVersion, setWmsVersion] = useState("1.3.0");
  const [isLoading, setIsLoading] = useState(false);
  const [capabilitiesError, setCapabilitiesError] = useState("");
  const lastFittedEndpointRef = useRef("");
  const wmsLayerRef = useRef(null);
  // Kept in sync with availableLayers state so effects can read it without
  // taking a dep on availableLayers (which would cause spurious layer recreation).
  const availableLayersRef = useRef([]);
  const [dimensionParams, setDimensionParams] = useState({});

  // Track whether this is the first initialization
  const isInitializingRef = useRef(true);
  // Track the last user-selected layer (to preserve across projection changes)
  const userSelectedLayerRef = useRef("");
  // Track the last user-selected projection (to preserve across layer changes)
  const userSelectedProjectionRef = useRef("");

  const endpoints = useMemo(() => normalizeEndpoints(wmsConfig), [wmsConfig]);
  const blacklistedLayersSet = useMemo(
    () => buildBlacklistedLayerSet(wmsConfig?.blacklistedLayers),
    [wmsConfig?.blacklistedLayers],
  );

  useEffect(() => {
    if (!endpoints.length) {
      setActiveEndpointId("");
      return;
    }

    const endpointExists = endpoints.some(
      (endpoint) => endpoint.id === activeEndpointId,
    );
    if (!endpointExists) {
      setActiveEndpointId(endpoints[0].id);
    }
  }, [endpoints, activeEndpointId]);

  const activeEndpoint = useMemo(
    () =>
      endpoints.find((endpoint) => endpoint.id === activeEndpointId) || null,
    [endpoints, activeEndpointId],
  );

  // Load capabilities when active endpoint changes.
  useEffect(() => {
    if (!activeEndpoint) {
      setAvailableLayers([]);
      setSelectedLayer("");
      setSelectedStyle("");
      return;
    }

    const controller = new AbortController();
    const capabilitiesFormat = new WMSCapabilities();

    const loadCapabilities = async () => {
      setIsLoading(true);
      setCapabilitiesError("");

      try {
        const response = await fetch(activeEndpoint.capabilitiesUrl, {
          signal: controller.signal,
        });
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const xml = await response.text();
        const parsed = capabilitiesFormat.read(xml);
        setWmsVersion(deriveCapabilitiesVersion(parsed));
        const capabilityLayers =
          parsed?.Capability?.Layer?.Layer &&
          Array.isArray(parsed.Capability.Layer.Layer)
            ? parsed.Capability.Layer.Layer
            : [];

        const namedLayers = capabilityLayers.flatMap((layer) =>
          collectNamedLayers(layer, []),
        );
        const filteredLayers = namedLayers.filter(
          (layer) => !blacklistedLayersSet.has(layer.name.toLowerCase()),
        );

        console.info("[METSIS/WMS] Capabilities parsed", {
          endpointId: activeEndpoint.id,
          endpointLabel: activeEndpoint.label,
          namedLayerCount: namedLayers.length,
          filteredLayerCount: filteredLayers.length,
          layersWithExtent: filteredLayers.filter((layer) =>
            Array.isArray(layer.geographicExtent),
          ).length,
        });

        setAvailableLayers(filteredLayers);

        // Only auto-select a layer if we're in initialization or the user hasn't selected one yet.
        if (isInitializingRef.current || !userSelectedLayerRef.current) {
          const initialLayer = selectInitialLayer(
            filteredLayers,
            wmsConfig?.preferredLayers,
          );
          setSelectedLayer(initialLayer);
          userSelectedLayerRef.current = "";
        } else {
          // Check if the user's previously selected layer still exists in the new layers.
          const userLayerExists = filteredLayers.some(
            (layer) => layer.name === userSelectedLayerRef.current,
          );
          if (userLayerExists) {
            setSelectedLayer(userSelectedLayerRef.current);
          } else {
            // If not, fall back to initial selection.
            const initialLayer = selectInitialLayer(
              filteredLayers,
              wmsConfig?.preferredLayers,
            );
            setSelectedLayer(initialLayer);
            userSelectedLayerRef.current = "";
          }
        }

        if (isInitializingRef.current) {
          isInitializingRef.current = false;
        }
      } catch (error) {
        if (error?.name !== "AbortError") {
          setCapabilitiesError(
            "Failed to load WMS capabilities for the selected endpoint.",
          );
          setWmsVersion("1.3.0");
        }
      } finally {
        setIsLoading(false);
      }
    };

    loadCapabilities();

    return () => {
      controller.abort();
    };
  }, [activeEndpoint, blacklistedLayersSet, wmsConfig?.preferredLayers]);

  // Keep availableLayersRef up-to-date — declared before the layer-creation
  // effect so the ref is always populated when that effect reads it.
  useEffect(() => {
    availableLayersRef.current = availableLayers;
  }, [availableLayers]);

  // Reset dimension params when the selected layer changes so the controls
  // start from the server-advertised defaults for the new layer.
  useEffect(() => {
    const dims =
      availableLayersRef.current.find((l) => l.name === selectedLayer)
        ?.dimensions ?? [];
    setDimensionParams(buildInitialDimParams(dims));
  }, [selectedLayer]);

  useEffect(() => {
    const selectedLayerDefinition = availableLayers.find(
      (layer) => layer.name === selectedLayer,
    );

    const hasStyle = selectedLayerDefinition?.styles?.some(
      (style) => style.name === selectedStyle,
    );
    if (!hasStyle) {
      setSelectedStyle(selectedLayerDefinition?.styles?.[0]?.name || "");
    }
  }, [availableLayers, selectedLayer, selectedStyle]);

  useEffect(() => {
    if (!mapInstance || !activeEndpoint || !selectedLayer) {
      return;
    }

    // Include initial dimension values (TIME, ELEVATION, DIM_*) so the very
    // first tile request goes to the server with the correct dimension params.
    const dims =
      availableLayersRef.current.find((l) => l.name === selectedLayer)
        ?.dimensions ?? [];
    const params = {
      LAYERS: selectedLayer,
      TILED: true,
      VERSION: wmsVersion,
      ...buildInitialDimParams(dims),
    };
    if (selectedStyle) {
      params.STYLES = selectedStyle;
    }

    const layer = new TileLayer({
      source: new TileWMS({
        url: activeEndpoint.serviceUrl,
        reprojectionErrorThreshold: 0.1,
        params,
      }),
    });

    mapInstance.addLayer(layer);
    wmsLayerRef.current = layer;

    // Notify parent of WMS layer change for legend display.
    // hasLegend is forwarded so the parent can skip rendering LegendPanel
    // for endpoints that don't advertise a LegendURL in their capabilities.
    if (typeof onWmsLayerChange === "function") {
      const hasLegend =
        availableLayers.find((l) => l.name === selectedLayer)?.hasLegend ??
        false;
      onWmsLayerChange(layer, selectedLayer, selectedStyle, hasLegend);
    }

    return () => {
      mapInstance.removeLayer(layer);
    };
  }, [mapInstance, activeEndpoint, selectedLayer, selectedStyle, wmsVersion]);

  // Apply dimension param changes (TIME, ELEVATION, DIM_*) to the active WMS
  // source without recreating the layer — OL handles a partial updateParams call.
  useEffect(() => {
    if (wmsLayerRef.current && Object.keys(dimensionParams).length > 0) {
      wmsLayerRef.current.getSource()?.updateParams(dimensionParams);
    }
  }, [dimensionParams]);

  const selectedLayerDefinition = availableLayers.find(
    (layer) => layer.name === selectedLayer,
  );

  const extentToFit = useMemo(() => {
    const availableExtents = availableLayers
      .map((layer) => layer.geographicExtent)
      .filter(Array.isArray);

    if (availableExtents.length > 1) {
      const merged = mergeGeographicExtents(availableExtents);
      console.info("[METSIS/WMS] Using merged extent across layers", {
        availableExtents,
        mergedExtent: merged,
      });
      return merged;
    }

    const singleExtent =
      selectedLayerDefinition?.geographicExtent || availableExtents[0] || null;
    console.info("[METSIS/WMS] Using single-layer extent", {
      selectedLayer: selectedLayerDefinition?.name || selectedLayer || null,
      extent: singleExtent,
    });
    return singleExtent;
  }, [availableLayers, selectedLayerDefinition]);

  useEffect(() => {
    if (!mapInstance || !activeEndpoint || !extentToFit) {
      return;
    }

    const supportedCodes = Array.isArray(supportedProjectionCodes)
      ? supportedProjectionCodes
      : [];
    const fallbackCode =
      currentProjection || mapInstance.getView()?.getProjection()?.getCode();

    // Only auto-switch projection on initialization, not on layer changes.
    const shouldSwitchProjection = isInitializingRef.current;

    const targetProjection = shouldSwitchProjection
      ? chooseBestProjectionForExtent(extentToFit, supportedCodes, {
          preferredCode: "EPSG:32661",
          fallbackCode,
        })
      : null;

    console.info("[METSIS/WMS] Fit decision", {
      selectedLayer: selectedLayer || "all",
      extentToFit,
      supportedCodes,
      currentProjection,
      fallbackCode,
      targetProjection,
      shouldSwitchProjection,
    });

    if (!targetProjection && shouldSwitchProjection) {
      return;
    }

    const fitProjection = targetProjection || fallbackCode;
    // Layer changes must preserve the user's area of interest within a product.
    const fitKey = `${activeEndpoint.serviceUrl}|${fitProjection}|${extentToFit.join(",")}`;
    if (lastFittedEndpointRef.current === fitKey) {
      console.debug("[METSIS/WMS] Skipping duplicate fit", { fitKey });
      return;
    }

    if (shouldSwitchProjection && targetProjection) {
      const switchedProjection = switchMapViewProjection(
        mapInstance,
        targetProjection,
      );
      const didFit = fitViewToGeographicExtent(
        mapInstance,
        extentToFit,
        targetProjection,
        {
          maxZoom: 12,
          padding: [16, 16, 16, 16],
        },
      );

      const view = mapInstance.getView();
      console.info("[METSIS/WMS] Applied map fit", {
        switchedProjection,
        didFit,
        projection: view?.getProjection()?.getCode(),
        center: view?.getCenter?.(),
        zoom: view?.getZoom?.(),
        resolution: view?.getResolution?.(),
        fitKey,
      });

      if (typeof onProjectionChange === "function") {
        onProjectionChange(targetProjection);
        userSelectedProjectionRef.current = targetProjection;
      }
    } else {
      const didFit = fitViewToGeographicExtent(
        mapInstance,
        extentToFit,
        fallbackCode,
        {
          maxZoom: 12,
          padding: [16, 16, 16, 16],
        },
      );

      const view = mapInstance.getView();
      console.info("[METSIS/WMS] Applied map fit (preserving projection)", {
        didFit,
        projection: view?.getProjection()?.getCode(),
        center: view?.getCenter?.(),
        zoom: view?.getZoom?.(),
        resolution: view?.getResolution?.(),
        fitKey,
      });
    }

    lastFittedEndpointRef.current = fitKey;
  }, [
    mapInstance,
    activeEndpoint,
    selectedLayer,
    extentToFit,
    supportedProjectionCodes,
    currentProjection,
    onProjectionChange,
  ]);

  // Handler for when user explicitly selects a layer.
  const handleLayerChange = (event) => {
    const newLayer = event.target.value;
    userSelectedLayerRef.current = newLayer;
    setSelectedLayer(newLayer);
  };

  // Notify parent when layer or style selection changes (for legend display)
  useEffect(() => {
    if (typeof onWmsLayerChange === "function" && wmsLayerRef.current) {
      const hasLegend =
        availableLayers.find((l) => l.name === selectedLayer)?.hasLegend ??
        false;
      onWmsLayerChange(
        wmsLayerRef.current,
        selectedLayer,
        selectedStyle,
        hasLegend,
      );
    }
  }, [selectedLayer, selectedStyle]);

  if (!endpoints.length) {
    return null;
  }

  return (
    <div className="metsis-wms-controls">
      {endpoints.length > 1 && (
        <label>
          Endpoint
          <select
            value={activeEndpointId}
            onChange={(event) => setActiveEndpointId(event.target.value)}
          >
            {endpoints.map((endpoint) => (
              <option key={endpoint.id} value={endpoint.id}>
                {endpoint.label}
              </option>
            ))}
          </select>
        </label>
      )}

      {isLoading && <WmsSpinner />}
      {capabilitiesError && <p>{capabilitiesError}</p>}

      {!isLoading && !capabilitiesError && availableLayers.length === 0 && (
        <p>No renderable layers found after applying blacklist rules.</p>
      )}

      {!isLoading && !capabilitiesError && availableLayers.length > 0 && (
        <>
          {selectedLayerDefinition && (
            <div className="wms-layer-info">
              {selectedLayerDefinition.title && (
                <div className="wms-layer-title">
                  {selectedLayerDefinition.title}
                </div>
              )}
              {selectedLayerDefinition.abstract && (
                <div className="wms-layer-abstract">
                  {selectedLayerDefinition.abstract}
                </div>
              )}
            </div>
          )}
          <label>
            Layer
            <select value={selectedLayer} onChange={handleLayerChange}>
              {availableLayers.map((layer) => (
                <option key={layer.name} value={layer.name}>
                  {layer.name || layer.title}
                </option>
              ))}
            </select>
          </label>

          {selectedLayerDefinition?.styles?.length > 0 && (
            <label>
              Style
              <select
                value={selectedStyle}
                onChange={(event) => setSelectedStyle(event.target.value)}
              >
                {selectedLayerDefinition.styles.map((style) => (
                  <option key={style.name} value={style.name}>
                    {style.title}
                  </option>
                ))}
              </select>
            </label>
          )}

          {selectedLayerDefinition?.dimensions?.length > 0 && (
            <WMSDimensionControls
              dimensions={selectedLayerDefinition.dimensions}
              layerKey={selectedLayer}
              onDimensionParamsChange={setDimensionParams}
            />
          )}
        </>
      )}
    </div>
  );
};

export default WMSLayerManager;

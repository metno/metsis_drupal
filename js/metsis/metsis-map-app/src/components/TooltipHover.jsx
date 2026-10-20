import { useEffect } from "preact/hooks";
import {
  getResultFeaturesAtPixel,
  setFeatureState,
  summarizeTitles,
  t,
} from "@utils/featureInfo.js";

const TooltipHover = ({ mapInstance }) => {
  useEffect(() => {
    if (!mapInstance) return;
    // Create a tooltip element
    const tooltipElement = document.createElement("div");
    tooltipElement.className = "metsis-map-tooltip ol-control";
    tooltipElement.style.position = "absolute";
    tooltipElement.style.display = "none";
    tooltipElement.style.pointerEvents = "none";
    tooltipElement.style.background = "rgba(0, 0, 0, 0.7)";
    tooltipElement.style.color = "#fff";
    tooltipElement.style.padding = "4px 8px";
    tooltipElement.style.borderRadius = "4px";
    tooltipElement.style.whiteSpace = "normal";
    tooltipElement.style.overflowWrap = "anywhere";
    tooltipElement.style.boxSizing = "border-box";
    tooltipElement.style.maxWidth = "100%";
    tooltipElement.style.fontSize = "12px";
    tooltipElement.style.zIndex = "1000";

    // Append the tooltip to the map container
    const mapContainer = mapInstance.getTargetElement();
    mapContainer.appendChild(tooltipElement);

    const hideTooltip = () => {
      tooltipElement.style.display = "none";
      tooltipElement.replaceChildren();
      setFeatureState(mapInstance, "hovered", []);
    };

    // Show the titles of all (overlapping) result features under the pointer.
    const displayFeatureInfo = (pixel, target) => {
      // Ignore controls (like zoom buttons) and overlays such as the popup.
      const features = target.closest(".ol-control, .ol-overlay-container")
        ? []
        : getResultFeaturesAtPixel(mapInstance, pixel);
      const { visible, remaining } = summarizeTitles(
        features.map((feature) => feature.get("title")),
      );

      setFeatureState(
        mapInstance,
        "hovered",
        features.map((feature) => feature.get("id")),
      );
      mapContainer.style.cursor = features.length ? "pointer" : "";

      if (!visible.length) {
        tooltipElement.style.display = "none";
        tooltipElement.replaceChildren();
        return;
      }

      const lines = visible.map((title) => {
        const line = document.createElement("div");
        line.textContent = title;
        return line;
      });
      if (lines.length > 1) {
        lines.forEach((line) => {
          line.style.paddingLeft = "0.75em";
          line.style.textIndent = "-0.75em";
          line.textContent = `\u2022 ${line.textContent}`;
        });
      }
      if (remaining > 0) {
        const more = document.createElement("div");
        more.style.fontStyle = "italic";
        more.textContent = t("+@count more", { "@count": remaining });
        lines.push(more);
      }
      tooltipElement.replaceChildren(...lines);
      tooltipElement.style.display = "block";
      tooltipElement.style.left = `${Math.max(0, Math.min(pixel[0], mapContainer.clientWidth - tooltipElement.offsetWidth))}px`;
      tooltipElement.style.top = `${Math.max(0, Math.min(pixel[1], mapContainer.clientHeight - tooltipElement.offsetHeight))}px`;
    };

    // Add pointermove event listener to the map
    const onPointerMove = (evt) => {
      if (evt.dragging) {
        hideTooltip();
        return;
      }
      displayFeatureInfo(evt.pixel, evt.originalEvent.target);
    };
    mapInstance.on("pointermove", onPointerMove);

    // Clicks open the feature popup, which replaces the tooltip.
    const onClick = () => {
      tooltipElement.style.display = "none";
    };
    mapInstance.on("singleclick", onClick);

    // Hide tooltip when the mouse leaves the map
    mapContainer.addEventListener("pointerleave", hideTooltip);
    mapInstance.on("change:size", hideTooltip);

    // Cleanup on component unmount
    return () => {
      tooltipElement.remove();
      mapContainer.removeEventListener("pointerleave", hideTooltip);
      mapInstance.un("change:size", hideTooltip);
      mapInstance.un("pointermove", onPointerMove);
      mapInstance.un("singleclick", onClick);
      mapContainer.style.cursor = "";
    };
  }, [mapInstance]);

  return null; // This component adds functionality to the map but doesn't render anything itself
};

export default TooltipHover;

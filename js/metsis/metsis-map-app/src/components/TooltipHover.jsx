import { useEffect } from "preact/hooks";

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
      tooltipElement.textContent = "";
    };

    // Function to display feature information
    const displayFeatureInfo = (pixel, target) => {
      // Check if the target is a control (like zoom buttons) and ignore
      const feature = target.closest(".ol-control")
        ? undefined
        : mapInstance.forEachFeatureAtPixel(pixel, (feature) => feature);

      const title = feature?.get("title");
      if (!title) {
        hideTooltip();
        return;
      }

      tooltipElement.textContent = title;
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

    // Add click event listener to display feature info
    const onClick = (evt) => {
      displayFeatureInfo(evt.pixel, evt.originalEvent.target);
    };
    mapInstance.on("click", onClick);

    // Hide tooltip when the mouse leaves the map
    mapContainer.addEventListener("pointerleave", hideTooltip);
    mapInstance.on("change:size", hideTooltip);

    // Cleanup on component unmount
    return () => {
      tooltipElement.remove();
      mapContainer.removeEventListener("pointerleave", hideTooltip);
      mapInstance.un("change:size", hideTooltip);
      mapInstance.un("pointermove", onPointerMove);
      mapInstance.un("click", onClick);
    };
  }, [mapInstance]);

  return null; // This component adds functionality to the map but doesn't render anything itself
};

export default TooltipHover;

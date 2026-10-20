import { render } from "preact";
import { useEffect, useRef, useState } from "preact/hooks";
import Overlay from "ol/Overlay.js";
import {
  formatTemporalShort,
  getResultFeaturesAtPixel,
  safeHttpUrl,
  setFeatureState,
  t,
} from "@utils/featureInfo.js";

const HIGHLIGHT_CLASS = "metsis-search-row--map-highlight";

function getBaseUrl() {
  return globalThis.drupalSettings?.path?.baseUrl || "/";
}

function findResultRow(solrId) {
  return solrId ? document.getElementById(solrId) : null;
}

function showInResults(solrId) {
  const row = findResultRow(solrId);
  if (!row) {
    return;
  }
  row.scrollIntoView({ behavior: "smooth", block: "center" });
  row.classList.add(HIGHLIGHT_CLASS);
  globalThis.setTimeout(() => row.classList.remove(HIGHLIGHT_CLASS), 2500);
}

// Reuse the result row's HTMX trigger so the dialog behaves exactly as in the
// result list; fall back to an HTMX request or the standalone metadata page.
function openMetadata(solrId) {
  const trigger = findResultRow(solrId)?.querySelector(
    ".metsis-metadata-modal-trigger",
  );
  if (trigger) {
    trigger.click();
    return;
  }
  const id = encodeURIComponent(solrId);
  const root = document.getElementById("metsis-metadata-modal-root");
  const htmx = globalThis.htmx;
  if (htmx && root) {
    htmx
      .ajax("GET", `${getBaseUrl()}metsis/metadata/htmx/${id}`, {
        target: root,
        swap: "innerHTML",
        select: "dialog[data-metsis-metadata-dialog]",
      })
      .then(() => globalThis.Drupal?.metsis?.vocabPopover?.afterSwap?.(root));
    return;
  }
  globalThis.open(`${getBaseUrl()}metsis/metadata/${id}`, "_blank", "noopener");
}

const FeatureDetail = ({ item, onBack }) => {
  const temporal = formatTemporalShort(item.temporal_start, item.temporal_end);
  const landingPage = safeHttpUrl(item.landing_page);
  const hasRow = Boolean(findResultRow(item.solr_id));

  return (
    <div className="metsis-feature-popup__detail">
      {onBack && (
        <button
          type="button"
          className="metsis-feature-popup__back"
          onClick={onBack}
        >
          {`\u2190 ${t("Back to list")}`}
        </button>
      )}
      <h3 className="metsis-feature-popup__title">{item.title}</h3>
      {item.is_parent && (
        <span className="metsis-feature-popup__badge">{t("Collection")}</span>
      )}
      {temporal && (
        <p className="metsis-feature-popup__temporal">
          <span className="visually-hidden">{t("Temporal extent")}: </span>
          {temporal}
        </p>
      )}
      <div className="metsis-feature-popup__actions">
        {hasRow && (
          <button
            type="button"
            className="metsis-feature-popup__action"
            onClick={() => showInResults(item.solr_id)}
          >
            {t("Show in results")}
          </button>
        )}
        {item.solr_id && (
          <button
            type="button"
            className="metsis-feature-popup__action"
            onClick={() => openMetadata(item.solr_id)}
          >
            {t("View metadata")}
          </button>
        )}
        {landingPage && (
          <a
            className="metsis-feature-popup__action"
            href={landingPage}
            target="_blank"
            rel="noopener noreferrer"
          >
            {t("Landing page")}
            <span className="visually-hidden">
              {" "}
              ({t("opens in a new tab")})
            </span>
          </a>
        )}
      </div>
    </div>
  );
};

const PopupContent = ({ items, mapInstance, onClose }) => {
  const [activeId, setActiveId] = useState(
    items.length === 1 ? items[0].id : null,
  );
  const rootRef = useRef(null);
  const active = items.find((item) => item.id === activeId);

  // Grey out every feature that is not part of the current popup view.
  useEffect(() => {
    setFeatureState(
      mapInstance,
      "selected",
      active ? [active.id] : items.map((item) => item.id),
    );
    rootRef.current?.querySelector("button, a")?.focus({ preventScroll: true });
  }, [activeId]);

  const highlight = (id) =>
    setFeatureState(mapInstance, "hovered", id ? [id] : []);

  return (
    <div
      ref={rootRef}
      className="metsis-feature-popup__inner"
      role="dialog"
      aria-label={
        active
          ? active.title
          : t("@count datasets at this location", { "@count": items.length })
      }
    >
      <button
        type="button"
        className="metsis-feature-popup__close"
        aria-label={t("Close")}
        onClick={onClose}
      >
        {"\u00d7"}
      </button>
      {active ? (
        <FeatureDetail
          item={active}
          onBack={items.length > 1 ? () => setActiveId(null) : null}
        />
      ) : (
        <div className="metsis-feature-popup__list">
          <p className="metsis-feature-popup__heading">
            {t("@count datasets at this location", { "@count": items.length })}
          </p>
          <ul>
            {items.map((item) => (
              <li key={item.id}>
                <button
                  type="button"
                  onClick={() => setActiveId(item.id)}
                  onMouseEnter={() => highlight(item.id)}
                  onMouseLeave={() => highlight(null)}
                  onFocus={() => highlight(item.id)}
                  onBlur={() => highlight(null)}
                >
                  {item.title || item.id}
                  {item.is_parent && (
                    <span className="metsis-feature-popup__badge">
                      {t("Collection")}
                    </span>
                  )}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
};

/**
 * Popup listing the result features at a clicked location.
 */
const FeaturePopup = ({ mapInstance, geojsonFeatures, projection }) => {
  useEffect(() => {
    if (!mapInstance) return;

    // OpenLayers moves the overlay element into its own container, so the
    // popup is rendered into a detached element instead of the Preact tree.
    const element = document.createElement("div");
    element.className = "metsis-feature-popup";
    const overlay = new Overlay({
      element,
      positioning: "bottom-center",
      offset: [0, -12],
      stopEvent: true,
      autoPan: { animation: { duration: 200 }, margin: 20 },
    });
    mapInstance.addOverlay(overlay);

    const close = () => {
      render(null, element);
      overlay.setPosition(undefined);
      setFeatureState(mapInstance, "selected", []);
      setFeatureState(mapInstance, "hovered", []);
      document.removeEventListener("keydown", onKeyDown);
    };

    function onKeyDown(event) {
      if (event.key === "Escape") {
        close();
      }
    }

    const onClick = (evt) => {
      const items = getResultFeaturesAtPixel(mapInstance, evt.pixel).map(
        (feature) => {
          const properties = { ...feature.getProperties() };
          delete properties.geometry;
          return properties;
        },
      );
      if (!items.length) {
        close();
        return;
      }
      // Remount so a new click always starts in its initial view.
      render(null, element);
      render(
        <PopupContent
          items={items}
          mapInstance={mapInstance}
          onClose={close}
        />,
        element,
      );
      // Open towards the larger free area so the popup stays inside the map.
      const [x, y] = evt.pixel;
      const [width, height] = mapInstance.getSize();
      const vertical = y < height / 2 ? "top" : "bottom";
      const horizontal =
        x < width / 3 ? "left" : x > (2 * width) / 3 ? "right" : "center";
      overlay.setPositioning(`${vertical}-${horizontal}`);
      overlay.setOffset([0, vertical === "top" ? 12 : -12]);
      element.style.setProperty(
        "--metsis-feature-popup-max-height",
        `${Math.max(120, Math.round(height / 2 - 24))}px`,
      );
      element.style.setProperty(
        "--metsis-feature-popup-max-width",
        `${Math.max(200, Math.round(width - 24))}px`,
      );
      overlay.setPosition(evt.coordinate);
      document.addEventListener("keydown", onKeyDown);
    };
    mapInstance.on("singleclick", onClick);

    return () => {
      close();
      mapInstance.un("singleclick", onClick);
      mapInstance.removeOverlay(overlay);
    };
  }, [mapInstance, geojsonFeatures, projection]);

  return null;
};

export default FeaturePopup;

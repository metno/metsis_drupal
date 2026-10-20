import test from "node:test";
import assert from "node:assert/strict";
import {
  featureVisualState,
  formatTemporalShort,
  isGlobeSpanning,
  safeHttpUrl,
  summarizeTitles,
  uniqueBy,
} from "../src/utils/featureInfo.js";

test("summarizeTitles limits titles and counts the rest", () => {
  const titles = ["a", "b", "", "c", "d", "e", "f", "g"];
  assert.deepEqual(summarizeTitles(titles), {
    visible: ["a", "b", "c", "d", "e"],
    remaining: 2,
  });
  assert.deepEqual(summarizeTitles(["a"]), { visible: ["a"], remaining: 0 });
});

test("uniqueBy keeps first occurrence and drops empty keys", () => {
  const items = [{ id: "x" }, { id: "y" }, { id: "x" }, { id: "" }];
  assert.deepEqual(
    uniqueBy(items, (item) => item.id),
    [{ id: "x" }, { id: "y" }],
  );
});

test("formatTemporalShort uses date parts and open-ended extents", () => {
  assert.equal(
    formatTemporalShort("2020-01-01T00:00:00Z", "2021-12-31T23:59:59Z"),
    "2020-01-01 to 2021-12-31",
  );
  assert.equal(formatTemporalShort("2020-01-01T00:00:00Z", ""), "2020-01-01");
  assert.equal(formatTemporalShort("", "2021-01-01"), "");
});

test("isGlobeSpanning detects world polygons from lon/lat input", () => {
  const world = {
    type: "Polygon",
    coordinates: [
      [
        [180, -90],
        [180, 90],
        [-180, 90],
        [-180, -90],
        [180, -90],
      ],
    ],
  };
  const small = {
    type: "Polygon",
    coordinates: [
      [
        [5, 58],
        [12, 58],
        [12, 64],
        [5, 64],
        [5, 58],
      ],
    ],
  };
  assert.equal(isGlobeSpanning(world), true);
  assert.equal(isGlobeSpanning(small), false);
  assert.equal(
    isGlobeSpanning({ type: "Point", coordinates: [10, 60] }),
    false,
  );
  assert.equal(isGlobeSpanning(null), false);
});

test("featureVisualState resolves active, muted and default", () => {
  const state = { hovered: new Set(["h"]), selected: new Set(["s"]) };
  assert.equal(featureVisualState("h", state), "active");
  assert.equal(featureVisualState("s", state), "active");
  assert.equal(featureVisualState("o", state), "muted");
  assert.equal(
    featureVisualState("o", { hovered: new Set(), selected: new Set() }),
    "default",
  );
});

test("safeHttpUrl only allows HTTP(S) URLs", () => {
  assert.equal(safeHttpUrl("https://example.org/a"), "https://example.org/a");
  assert.equal(safeHttpUrl("javascript:alert(1)"), "");
  assert.equal(safeHttpUrl(""), "");
});

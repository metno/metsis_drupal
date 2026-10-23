/**
 * @file
 * Clears composite bbox inputs, including map-only hidden coordinates.
 */

((Drupal, once) => {
  Drupal.behaviors.metsisBboxSummary = {
    attach(context) {
      once(
        "metsis-bbox-summary",
        ".views-filters-summary a.remove-filter",
        context,
      ).forEach((link) => {
        link.addEventListener(
          "click",
          (event) => {
            const identifier = link.dataset.removeSelector?.split(":")[0];
            const summary = link.closest(".views-filters-summary");
            const handler = new Drupal.ViewsFiltersSummaryHandler(
              summary.parentElement,
            );
            const form = handler.getExposedForm();
            if (!form || !identifier) {
              return;
            }
            const inputs = ["minX", "maxX", "minY", "maxY"].map((coordinate) =>
              form.elements.namedItem(`${identifier}[${coordinate}]`),
            );
            if (
              !inputs.every((input) =>
                input?.hasAttribute("data-metsis-bbox-coordinate"),
              )
            ) {
              return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            inputs.forEach((input) => {
              input.value = "";
            });
            handler.submit(form);
          },
          true,
        );
      });
    },
  };
})(Drupal, once);

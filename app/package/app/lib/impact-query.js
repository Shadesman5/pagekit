// A failed read or a payload that is not the three-part answer leaves the
// proceed control out, the same as a non-empty blockers list. The server still
// refuses if that control is reached.

export function canProceed(impact) {
  return isImpactAnswer(impact) && impact.blockers.length === 0;
}

export function readImpact(vm, name) {
  vm.impact = null;
  vm.impactFailed = false;

  return vm.$http.post('admin/system/package/impact', { name }).then(
    response => {
      const data = response && response.data;

      if (!isImpactAnswer(data)) {
        vm.impactFailed = true;
        return;
      }

      vm.impact = data;
    },
    () => {
      vm.impactFailed = true;
    }
  );
}

function isImpactAnswer(data) {
  const risk = data && data.dataRisk;

  return Boolean(
    data &&
    Array.isArray(data.blockers) &&
    Array.isArray(data.orphans) &&
    risk &&
    typeof risk.migrations === 'boolean' &&
    typeof risk.config === 'boolean' &&
    Array.isArray(risk.nodes) &&
    Array.isArray(risk.tables)
  );
}

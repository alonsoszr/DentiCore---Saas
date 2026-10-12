/** Si el plan de la clínica incluye la función (`ai`, `risk`, `analytics`; DD-16, RN-08). */
export function hasFeature(user, feature) {
  return user?.tenant?.plan?.[`includes_${feature}`] === true
}

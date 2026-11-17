/**
 * Indexed pages of external sites come up in the site's own Smart Search and link to
 * the external site.
 *
 * Needs a development site whose manual task has run (docs/development.md) and the
 * layout from overrides/ copied into the template. Results of the external task use
 * the layout "external"; the shown URL must be the external address, not the local
 * host followed by it.
 */
describe('Smart Search results of external sites', () => {
  it('link to the external site and show its address once', () => {
    cy.visit('/index.php?option=com_finder&view=search&q=joomla');

    cy.get('.result__item').should('have.length.greaterThan', 0);

    cy.get('.result__item a.result__title-link').then(($links) => {
      const external = [...$links].filter((a) => /^https:\/\/(manual|magazine)\.joomla\.org\//.test(a.getAttribute('href')));

      expect(external.length, 'results linking to manual or magazine').to.be.greaterThan(0);

      external.forEach((a) => {
        const cite = a.querySelector('cite');

        expect(cite, 'shown URL').to.exist;
        expect(cite.textContent).to.equal(a.getAttribute('href'));
      });
    });
  });

  it('shows external titles as text, never as markup', () => {
    cy.visit('/index.php?option=com_finder&view=search&q=joomla');

    cy.get('.result__title-text script, .result__description script').should('not.exist');
  });
});

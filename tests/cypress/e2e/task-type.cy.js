/**
 * The Task Scheduler offers the task type, and its form has every parameter the
 * crawler reads, with safe defaults.
 */
describe('The external site task type', () => {
  beforeEach(() => {
    cy.loginToAdmin();
  });

  it('is offered when creating a new task', () => {
    cy.visit('/administrator/index.php?option=com_scheduler&view=select');

    cy.contains('Smart Search: index external site').should('be.visible');
  });

  it('has the sources, URL rules, content selectors and crawl settings with safe defaults', () => {
    cy.visit('/administrator/index.php?option=com_scheduler&task=task.add&type=finderexternal.index');

    [
      'site_label', 'sitemaps', 'feeds', 'start_urls',
      'scope', 'exclude', 'index_only',
      'content_selector', 'title_selector', 'remove_selectors',
      'date_selector', 'author_selector', 'category_selector',
      'user_agent',
    ].forEach((name) => cy.get(`#jform_params_${name}`).should('exist'));

    cy.get('#jform_params_max_depth').should('have.value', '0');
    cy.get('#jform_params_content_selector').should('have.value', 'main, article');
    cy.get('#jform_params_title_selector').should('have.value', 'h1');
    cy.get('#jform_params_description_source').should('have.value', 'meta');
    cy.get('#jform_params_language').should('have.value', '*');
    cy.get('#jform_params_access').should('have.value', '1');
    cy.get('#jform_params_recheck_days').should('have.value', '7');
    cy.get('#jform_params_request_delay').should('have.value', '500');
    cy.get('#jform_params_respect_robots1').should('be.checked');
    cy.get('#jform_params_time_budget').should('have.value', '20');

    cy.get('#toolbar-cancel button, button.button-cancel').first().click();
  });
});

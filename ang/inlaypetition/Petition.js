(function(angular, $, _) {

  angular.module('inlaypetition').config(function($routeProvider) {
      $routeProvider.when('/inlay/petition/:id', {
        controller: 'InlaypetitionPetition',
        controllerAs: '$ctrl',
        templateUrl: '~/inlaypetition/Petition.html',

        // If you need to look up data when opening the page, list it out
        // under "resolve".
        resolve: {
          various: function($route, crmApi4, $route) {
            const params = {
              inlayTypes: ['InlayType', 'get', {}, 'class'],
              groups: ['Group', 'get', {
                select: ["id", "title"],
                where: [["group_type", "=", 2], ["is_active", "=", true], ["is_hidden", "=", false]],
                orderBy: {"title":"ASC"}
              }],
              messageTpls: [ 'MessageTemplate', 'get', {
                select: ["id", "msg_title", "msg_subject"],
                where: [
                  ["is_active", "=", true], ["is_sms", "=", false],
                  ["workflow_id", "IS NULL"]
                ],
                orderBy: {msg_title: 'ASC'}},
                'id']
            };
            if ($route.current.params.id > 0) {
              params.inlay = ['Inlay', 'get', {where: [["id", "=", $route.current.params.id]]}, 0];
            }
            return crmApi4(params);
          },
        }
      });
    }
  );

  // The controller uses *injection*. This default injects a few things:
  //   $scope -- This is the set of variables shared between JS and HTML.
  //   crmApi, crmStatus, crmUiHelp -- These are services provided by civicrm-core.
  angular.module('inlaypetition').controller('InlaypetitionPetition', function($scope, crmApi4, crmStatus, crmUiHelp, various) {
    // The ts() and hs() functions help load strings for this module.
    var ts = $scope.ts = CRM.ts('inlaypetition');
    var hs = $scope.hs = crmUiHelp({file: 'CRM/inlaypetition/Petition'}); // See: templates/CRM/inlaypetition/Petition.hlp
    // Local variable for this controller (needed when inside a callback fn where `this` is not available).
    var ctrl = this;

    $scope.mailingGroups = various.groups;
    $scope.inlayType = various.inlayTypes['Civi\\Inlay\\Petition'];
    console.log({various}, $scope.inlayType);
    $scope.mailingGroups = various.groups;
    $scope.messageTpls = various.messageTpls;

    if (various.inlay) {
      $scope.inlay = various.inlay;
    }
    else {
      $scope.inlay = {
        'class' : 'Civi\\Inlay\\Petition',
        name: 'New ' + $scope.inlayType.name,
        public_id: 'new',
        id: 0,
        config: JSON.parse(JSON.stringify($scope.inlayType.defaultConfig)),
      };
    }
    const inlay = $scope.inlay;

    // Define all the networks we support here.
    const knownSocials = {
      twitter: 'Twitter',
      facebook: 'Facebook',
      whatsapp: 'WhatsApp',
      email: 'Email',
    };
    $scope.smShares = [];
    inlay.config.socials.forEach(sm => {
      $scope.smShares.push({active: true, name: sm, label: knownSocials[sm]});
      delete knownSocials[sm];
    });
    Object.keys(knownSocials).forEach(sm => {
      $scope.smShares.push({active: false, name: sm, label: knownSocials[sm]});
    });

    $scope.smActive = function(sm) {
      return $scope.smShares.find(x => x.name === sm).active;
    };

    $scope.save = function() {

      // Re-form the socials part of inlay.
      inlay.config.socials = [];
      $scope.smShares.forEach(sm => {
        if (sm.active) {
          inlay.config.socials.push(sm.name);
        }
      });
      console.log("Saving " + JSON.stringify(inlay));

      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Saving...'), success: ts('Saved')},
        // The save action. Note that crmApi() returns a promise.
        crmApi4('Inlay', 'save', { records: [inlay] })
      ).then(r => {
        console.log("save result", r);
        window.location = CRM.url('civicrm/a?#inlays');
      });
    };

  });

})(angular, CRM.$, CRM._);

Scalr.regPage('Scalr.ui.security.groups.view', function (loadParams, moduleParams) {
	var store = Ext.create('store.store', {
		fields: [
			'name', 'description', 'id', 'vpcId',
			'farm_name', 'farm_id', 'role_name', 'farm_roleid'
		],
		proxy: {
			type: 'scalr.paging',
			url: '/security/groups/xListGroups/'
		},
		remoteSort: true
	});

	return Ext.create('Ext.grid.Panel', {
		title: Scalr.utils.getPlatformName(loadParams['platform']) + ' &raquo; Security groups &raquo; View',
		scalrOptions: {
			'reload': true,
			'maximize': 'all'
		},
		store: store,
		stateId: 'grid-security-groups-view',
		stateful: true,
		plugins: {
			ptype: 'gridstore'
		},
		tools: [{
			xtype: 'gridcolumnstool'
		}],

		viewConfig: {
			emptyText: "No security groups found",
			loadingText: 'Loading security groups ...'
		},

		columns: [
		    { header: "ID", width: 180, dataIndex: 'id', sortable: true },
		    { header: 'Used by', flex: 1, dataIndex: 'farm_id', sortable: false, xtype: 'templatecolumn', tpl:
				'<tpl if="farm_id">' +
					'<a href="#/farms/{farm_id}/view" title="Farm {farm_name}">{farm_name}</a>' +
					'<tpl if="farm_roleid">' +
						'&nbsp;&rarr;&nbsp;<a href="#/farms/{farm_id}/roles/{farm_roleid}/view" title="Role {role_name}">{role_name}</a> ' +
					'</tpl>' +
				'<tpl else><img src="' + Ext.BLANK_IMAGE_URL + '" class="x-icon-minus" /></tpl>'
			},
			{ header: "Name", flex: 1, dataIndex: 'name', sortable: true },
			{ header: "Description", flex: 2, dataIndex: 'description', sortable: true },
			{ header: "VPC ID", width: 180, dataIndex: 'vpcId', sortable: true },
			{
				xtype: 'optionscolumn2',
				menu: [{
                    iconCls: 'x-menu-icon-edit',
                    text:'Edit',
                    menuHandler: function(data) {
						Scalr.event.fireEvent('redirect', '#/security/groups/' + data['id'] + '/edit?platform=' + loadParams['platform'] + (Scalr.isCloudstack(loadParams['platform']) ? '' : '&cloudLocation=' + store.proxy.extraParams.cloudLocation));
					}
                }]
			}
		],

        multiSelect: true,
        selModel: {
            selType: 'selectedmodel'
        },

        listeners: {
            selectionchange: function(selModel, selections) {
                this.down('scalrpagingtoolbar').down('#delete').setDisabled(!selections.length);
            }
        },

        dockedItems: [{
            xtype: 'scalrpagingtoolbar',
            ignoredLoadParams: ['platform'],
            store: store,
            dock: 'top',
			beforeItems: [{
                text: 'Add group',
                cls: 'x-btn-green-bg',
                handler: function() {
                    Scalr.event.fireEvent('redirect', '#/security/groups/create?platform=' + loadParams['platform'] + (Scalr.isCloudstack(loadParams['platform']) ? '' : '&cloudLocation=' + store.proxy.extraParams.cloudLocation));
                }
            }],
            afterItems: [{
                ui: 'paging',
                itemId: 'delete',
                iconCls: 'x-tbar-delete',
                tooltip: 'Select one or more security group(s) to delete them',
                disabled: true,
                handler: function() {
                    var request = {
                        confirmBox: {
                            type: 'delete',
                            msg: 'Delete selected security group(s): %s ?'
                        },
                        processBox: {
                            type: 'delete',
                            msg: 'Deleting group(s) ...'
                        },
                        url: '/security/groups/xRemove',
                        success: function() {
                            store.load();
                        }
                    }, records = this.up('grid').getSelectionModel().getSelection(), data = [];

                    request.confirmBox.objects = [];
                    for (var i = 0, len = records.length; i < len; i++) {
                        data.push(records[i].get('id'));
                        request.confirmBox.objects.push(records[i].get('name'));
                    }
                    request.params = { groups: Ext.encode(data), platform:loadParams['platform'], cloudLocation: store.proxy.extraParams.cloudLocation };
                    Scalr.Request(request);
                }
            }],
            items: [{
                xtype: 'filterfield',
                store: store
            }, ' ', {
                xtype: 'fieldcloudlocation',
                itemId: 'cloudLocation',
                store: {
                    fields: [ 'id', 'name' ],
                    data: moduleParams.locations,
                    proxy: 'object'
                },
                hidden: Scalr.isCloudstack(loadParams['platform']),
                gridStore: store
            }]
        }]
	});
});

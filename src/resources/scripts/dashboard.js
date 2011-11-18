(function($) {


var Dashboard = Base.extend({

	constructor: function()
	{
		this.dom = {};

		this.dom.table = document.createElement('table');
		this.dom.table.className = 'widgets'
		document.getElementById('main').appendChild(this.dom.table);
		this.dom.tr = document.createElement('tr');
		this.dom.table.appendChild(this.dom.tr);

		this.widgets = [];
		var $widgets = $('.widget');
		for (var w = 0; w < $widgets.length; w++)
		{
			this.widgets.push(new Dashboard.Widget($widgets[w]));
		}

		this.cols = [];

		$(window).on('resizeWidth.dashboard', $.proxy(this, '_setCols'));
		setTimeout($.proxy(this, '_setCols'), 1);
	},

	_setCols: function(event)
	{
		var totalWidth = blx.windowWidth - Dashboard.gutterWidth,
			totalCols = Math.floor(totalWidth / (Dashboard.minColWidth + Dashboard.gutterWidth)),
			newColWidth = Math.floor(((totalWidth) / totalCols) - Dashboard.gutterWidth);

		if (this.totalCols !== (this.totalCols = totalCols))
		{
			// -------------------------------------------
			//  Cancel the current transitions
			// -------------------------------------------

			if (this.transition && this.transition.playing)
			{
				this.transition.stop();
			}

			// -------------------------------------------
			//  Create the new columns
			// -------------------------------------------

			var oldCols = this.cols;
			this.colWidth = 100 / this.totalCols;
			this.cols = [];

			for (var c = 0; c < totalCols; c++)
			{
				this.cols[c] = new Dashboard.Col(c);
			}

			// -------------------------------------------
			//  Record the old widget offsets
			// -------------------------------------------

			if (event)
			{
				this.mainOffset = blx.cp.dom.$main.offset();
				var oldWidgetPositions = this._getWidgetPositions();
			}

			// -------------------------------------------
			//  Put them in their new places
			// -------------------------------------------

			for (var w in this.widgets)
			{
				this.widgets[w].appendToCol(this._getShortestCol());
			}

			// -------------------------------------------
			//  Remove the old columns
			// -------------------------------------------

			for (var c in oldCols)
			{
				oldCols[c].remove();
			}

			// -------------------------------------------
			//  Animate the widgets into place
			// -------------------------------------------

			if (event)
			{
				var targetWidgetPositions = this._getWidgetPositions();

				var widgetTransitions = [];

				for (var w in this.widgets)
				{
					var widget = this.widgets[w];

					widget.$elem.css({
						position: 'absolute',
						top: oldWidgetPositions[w].top,
						left: oldWidgetPositions[w].left,
						width: this.colWidth+'px'
					});

					widgetTransitions[w] = new blx.Transition(widget.$elem, {
						top: targetWidgetPositions[w].top,
						left: targetWidgetPositions[w].left,
						width: newColWidth
					}, {
						inBatch: true
					});
				}

				this.transition = new blx.BatchTransition(widgetTransitions, {
					onFinish: $.proxy(function()
						{
							for (var w in this.widgets) {
								this.widgets[w].$elem.css({
									position: 'relative',
									top: '',
									left: '',
									width: ''
								});
							}
						}, this)
				});
			}
		}
		else
		{
			// -------------------------------------------
			//  Update the transitions
			// -------------------------------------------

			if (this.transition && this.transition.playing)
			{
				for (var w in this.widgets)
				{
					var widget = this.widgets[w];
					this.transition.transitions[w].targets.left = widget.col.getLeftPos();
					this.transition.transitions[w].targets.width = newColWidth;
				}
			}
		}

		this.colWidth = newColWidth;
	},

	_getShortestCol: function()
	{
		var shortestCol,
			shortestColHeight;

		for (c in this.cols)
		{
			var colHeight = this.cols[c].getHeight();

			if (typeof shortestCol == 'undefined' || colHeight < shortestColHeight)
			{
				shortestCol = this.cols[c];
				shortestColHeight = colHeight;
			}
		}

		return shortestCol;
	},

	_getWidgetPositions: function()
	{
		var positions = [];

		for (var w in this.widgets)
		{
			var widget = this.widgets[w],
				offset = widget.$elem.offset();

			positions[w] = {
				top: offset.top - this.mainOffset.top,
				left: offset.left - this.mainOffset.left
			};
		}

		return positions;
	}
},
{
	gutterWidth: 20,
	minColWidth: 280
});


Dashboard.Col = Base.extend({

	constructor: function(index)
	{
		this.index = index;
		this.dom = {};
		this.dom.td = document.createElement('td');
		this.dom.td.className = 'col';
		dashboard.dom.tr.appendChild(this.dom.td);
		this.dom.div = document.createElement('div');
		this.dom.td.appendChild(this.dom.div);

		$(this.dom.td).width(dashboard.colWidth+'%');
	},

	addWidget: function(widget)
	{
		$(this.dom.div).append(widget);
	},

	getHeight: function()
	{
		return $(this.dom.div).height();
	},

	getLeftPos: function()
	{
		return $(this.dom.div).offset().left;
	},

	remove: function()
	{
		$(this.dom.td).remove();
	}

});


Dashboard.Widget = Base.extend({

	constructor: function(elem)
	{
		this.$elem = $(elem);
	},

	appendToCol: function(col)
	{
		this.col = col;
		this.col.addWidget(this.$elem);
	}
});


window.dashboard = new Dashboard();


})(jQuery);

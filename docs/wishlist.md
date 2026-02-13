# Wishlist for the post-MVP world

## Web interface

- A splash page which introduces the idea of CrowdSky, with some stock astronomy images in the background (preferably for a google search for Seestar images).
- The splash page should include the information that theis will be used both as a storage solution for seestar users, and also as a resource for citizen science contributions to the time-domain astornomy community.
- You are given full artistic licence do design this page as you see fit.
- There should be a link to the existing sign-up page

### upload.php
- When the user decides to upload many files at once (e.g. >20) the list should continue to increase down the page, as it does now, but long lists should be contained in a text box with a scroll bar on the side.
- The "finalise and create stacking jobs" button should appear on the same line as the progress bar at the top of the page, but to the left of the progress bar. While files are uploading it should appear grey out or inactive. Once all the files have been uploaded it should appear active.
- Currently the upload job is orphaned if the user swaps pages to e.g. the "Stacks" page. When the user returns the previously executing upload job is not visible - the empty upload page is displayed. If there are files still uploading, these should be persisted until completed.

### status.php
- Next to each Job in the "Job Status Overview" table there should be a delete button. If the user sees that a job has been orphaned somehow and is never reaching the "completed" status, they should be able to remove this from the list. This should delete any corresponding files from the webspace temp storage and remove the entry from the database.

### stacks.php
- The number of stacks has the potential to explode for individual users. We are defining chunk names down to the 10th of a degree, making 4.2e8 possible unique pointings for RA/Dec. Then we have the time aspect too. My suggestion here is a big request, and it may not be doable in PHP. Can we display a zoomable Aitov projection of the night sky with RA/De grid lines, and mark all the stacks available in the User's account with red dots on the map. The user should be able to hover over a dot and see how many (temporal) stacks are available for a given coordinate. Ideally, they would then be able to drag a square around the region they want, and this would select all stacks bounded by that area on sky. A small graph below the Aitof projection figure whould show a timeline of all stacks available in that region. By using sliders, the user can then select what temporal window they are interested in. A "Download All" button would allow the user to grab all stacked images within the spatial and temporal bounds.  


## Stacking Worker

- It feels like I should extract the stacking algorithm from seestarpy and create a seperate package for it. That way it is not trying to search for a seestar telescope on the network (seestar.local) every time a job is run. This will save 2 secs per job.
- Complementary to the previous wish, I wish to add a quick and efficient plate-solver algorithm that is tailored to the seestars and only used triangles (not quads) to compute the exact WCS solution. This would fit nicely in an additional package, outside of seestarpy, togeter with the stacking algo. 
- Ideally the worker would be able to run multiple jobs in parallel. The server has 32 cores available, so up to 16 jobs should be no problem. I quess the bottle neck is the download speed and I/O speed of the server. Nevertheless a multi-thread appreach to the worker could be a great benefit.


That's it for now. Have fun!

